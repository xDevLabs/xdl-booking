<?php

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Slot rules: a candidate [start, start + duration) is free when fewer than `capacity`
 * existing bookings overlap it once each side is padded by the buffer. This keeps a
 * buffer gap after every booking (and before it, so a new booking can't end too close).
 */
final class XDL_Booking_Availability {

	public static function now() {
		return new DateTimeImmutable('now', wp_timezone());
	}

	public static function first_date() {
		return self::now()->format('Y-m-d');
	}

	public static function last_date() {
		$days = max(1, (int) XDL_Booking::get('max_days_ahead'));
		return self::now()->modify("+{$days} days")->format('Y-m-d');
	}

	public static function is_valid_ymd($ymd) {
		$dt = DateTimeImmutable::createFromFormat('!Y-m-d', (string) $ymd, wp_timezone());
		return $dt && $dt->format('Y-m-d') === $ymd;
	}

	public static function is_open_day($ymd) {
		if (!self::is_valid_ymd($ymd) || $ymd < self::first_date() || $ymd > self::last_date()) {
			return false;
		}
		$weekday = (int) DateTimeImmutable::createFromFormat('!Y-m-d', $ymd, wp_timezone())->format('N');
		if (!in_array($weekday, array_map('intval', (array) XDL_Booking::get('working_days')), true)) {
			return false;
		}
		return !in_array($ymd, XDL_Booking::blocked_dates(), true);
	}

	private static function to_minutes($hm) {
		$parts = explode(':', (string) $hm);
		return (int) $parts[0] * 60 + (int) ($parts[1] ?? 0);
	}

	public static function to_hm($minutes) {
		return sprintf('%02d:%02d', intdiv($minutes, 60), $minutes % 60);
	}

	/**
	 * @param string $ymd
	 * @param array  $bookings rows with start_time/end_time for that date
	 * @return string[] H:i start times
	 */
	public static function slots_for($ymd, array $bookings) {
		if (!self::is_open_day($ymd)) {
			return array();
		}

		$open     = self::to_minutes(XDL_Booking::get('open_time'));
		$close    = self::to_minutes(XDL_Booking::get('close_time'));
		$duration = max(5, (int) XDL_Booking::get('duration'));
		$buffer   = max(0, (int) XDL_Booking::get('buffer'));
		$interval = max(5, (int) XDL_Booking::get('interval'));
		$capacity = max(1, (int) XDL_Booking::get('capacity'));

		$earliest = self::now()->modify('+' . max(0, (int) XDL_Booking::get('min_notice_hours')) . ' hours');

		$busy = array();
		foreach ($bookings as $b) {
			$busy[] = array(self::to_minutes($b->start_time), self::to_minutes($b->end_time));
		}

		$slots = array();
		for ($t = $open; $t + $duration <= $close; $t += $interval) {
			$start_dt = DateTimeImmutable::createFromFormat('!Y-m-d H:i', $ymd . ' ' . self::to_hm($t), wp_timezone());
			if ($start_dt < $earliest) {
				continue;
			}
			$end      = $t + $duration;
			$overlaps = 0;
			foreach ($busy as [$bs, $be]) {
				if ($t < $be + $buffer && $bs < $end + $buffer) {
					$overlaps++;
				}
			}
			if ($overlaps < $capacity) {
				$slots[] = self::to_hm($t);
			}
		}

		return apply_filters('xdl_booking_slots', $slots, $ymd, $bookings);
	}

	public static function slots($ymd) {
		if (!self::is_open_day($ymd)) {
			return array();
		}
		return self::slots_for($ymd, XDL_Booking_DB::blocking_between($ymd, $ymd));
	}

	/** @return array<string,bool> Y-m-d => has free slots, for every day of the month. */
	public static function month($ym) {
		$first = DateTimeImmutable::createFromFormat('!Y-m-d', $ym . '-01', wp_timezone());
		if (!$first) {
			return array();
		}
		$last = $first->modify('last day of this month');

		$by_date = array();
		foreach (XDL_Booking_DB::blocking_between($first->format('Y-m-d'), $last->format('Y-m-d')) as $row) {
			$by_date[$row->booking_date][] = $row;
		}

		$out = array();
		for ($d = $first; $d <= $last; $d = $d->modify('+1 day')) {
			$ymd       = $d->format('Y-m-d');
			$out[$ymd] = (bool) self::slots_for($ymd, $by_date[$ymd] ?? array());
		}
		return $out;
	}

	public static function end_time($hm) {
		return self::to_hm(self::to_minutes($hm) + max(5, (int) XDL_Booking::get('duration')));
	}
}
