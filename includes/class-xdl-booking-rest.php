<?php

if (!defined('ABSPATH')) {
	exit;
}

final class XDL_Booking_REST {

	const NS = 'xdl-booking/v1';

	const MAX_GUESTS      = 10;
	const MIN_FILL_SECS   = 3;
	const RATE_LIMIT      = 5;
	const RATE_WINDOW     = HOUR_IN_SECONDS;

	public function __construct() {
		add_action('rest_api_init', array($this, 'routes'));
	}

	public function routes() {
		register_rest_route(self::NS, '/month', array(
			'methods'             => 'GET',
			'callback'            => array($this, 'month'),
			'permission_callback' => '__return_true',
			'args'                => array(
				'month' => array('required' => true, 'type' => 'string', 'pattern' => '^\d{4}-\d{2}$'),
			),
		));

		register_rest_route(self::NS, '/slots', array(
			'methods'             => 'GET',
			'callback'            => array($this, 'slots'),
			'permission_callback' => '__return_true',
			'args'                => array(
				'date' => array('required' => true, 'type' => 'string', 'pattern' => '^\d{4}-\d{2}-\d{2}$'),
			),
		));

		register_rest_route(self::NS, '/bookings', array(
			'methods'             => 'POST',
			'callback'            => array($this, 'create'),
			'permission_callback' => '__return_true',
		));
	}

	public function month(WP_REST_Request $req) {
		$res = rest_ensure_response(array(
			'days' => XDL_Booking_Availability::month($req['month']),
			'min'  => XDL_Booking_Availability::first_date(),
			'max'  => XDL_Booking_Availability::last_date(),
		));
		$res->header('Cache-Control', 'no-store');
		return $res;
	}

	public function slots(WP_REST_Request $req) {
		$res = rest_ensure_response(array(
			'date'  => $req['date'],
			'slots' => XDL_Booking_Availability::slots($req['date']),
		));
		$res->header('Cache-Control', 'no-store');
		return $res;
	}

	public function create(WP_REST_Request $req) {
		$p = $req->get_params();

		// Bots: honeypot filled or form submitted implausibly fast.
		$rendered = (int) ($p['ts'] ?? 0);
		if (!empty($p['website']) || ($rendered && time() - $rendered < self::MIN_FILL_SECS)) {
			return $this->error('spam', __('Could not submit your booking. Please try again.', 'xdl-booking'), 400);
		}

		$ip       = $this->ip();
		$rate_key = 'xdl_booking_rl_' . md5($ip);
		$hits     = (int) get_transient($rate_key);
		if ($hits >= self::RATE_LIMIT) {
			return $this->error('rate_limited', __('Too many booking requests. Please try again later.', 'xdl-booking'), 429);
		}

		$data = $this->validate($p);
		if (is_wp_error($data)) {
			return $data;
		}

		global $wpdb;
		$lock = 'xdl_booking_' . $data['booking_date'];
		if (!$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 5)', $lock))) {
			return $this->error('busy', __('The system is busy. Please try again.', 'xdl-booking'), 503);
		}

		try {
			if (!in_array(substr($data['start_time'], 0, 5),XDL_Booking_Availability::slots($data['booking_date']), true)) {
				return $this->error('slot_taken', __('Sorry, this time slot is no longer available. Please choose another time.', 'xdl-booking'), 409);
			}
			$data['ip'] = $ip;
			$id         = XDL_Booking_DB::insert($data);
		} finally {
			$wpdb->query($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock));
		}

		if (!$id) {
			return $this->error('db_error', __('Could not save your booking. Please try again.', 'xdl-booking'), 500);
		}

		set_transient($rate_key, $hits + 1, self::RATE_WINDOW);

		$booking = XDL_Booking_DB::get($id);
		XDL_Booking_Mailer::booking_created($booking);
		do_action('xdl_booking_created', $id, $booking);

		$message = trim((string) XDL_Booking::get('success_message'));
		if ('' === $message) {
			$message = __('Thank you! Your appointment request has been received. We will contact you shortly to confirm.', 'xdl-booking');
		}

		return rest_ensure_response(array(
			'id'      => $id,
			'message' => $message,
			'summary' => sprintf(
				/* translators: 1: date, 2: time */
				__('%1$s at %2$s', 'xdl-booking'),
				XDL_Booking::format_date($booking->booking_date),
				XDL_Booking::format_time($booking->start_time)
			),
		));
	}

	/** @return array|WP_Error */
	private function validate(array $p) {
		$errors = array();

		$date = sanitize_text_field($p['date'] ?? '');
		$time = sanitize_text_field($p['time'] ?? '');
		if (!XDL_Booking_Availability::is_valid_ymd($date)) {
			$errors['date'] = __('Please choose a date.', 'xdl-booking');
		}
		if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time)) {
			$errors['time'] = __('Please choose a time.', 'xdl-booking');
		}

		$name = sanitize_text_field($p['name'] ?? '');
		if ('' === $name || mb_strlen($name) > 190) {
			$errors['name'] = __('Please enter your name.', 'xdl-booking');
		}

		$email = sanitize_email($p['email'] ?? '');
		if (!is_email($email)) {
			$errors['email'] = __('Please enter a valid email address.', 'xdl-booking');
		}

		$phone = preg_replace('/[^\d+]/', '', (string) ($p['phone'] ?? ''));
		if (!preg_match('/^\+?\d{6,20}$/', $phone)) {
			$errors['phone'] = __('Please enter a valid phone number.', 'xdl-booking');
		}

		$guests = array();
		$raw    = preg_split('/[\s,;]+/', (string) ($p['guest_emails'] ?? ''), -1, PREG_SPLIT_NO_EMPTY);
		foreach ($raw as $g) {
			$g = sanitize_email($g);
			if (!is_email($g)) {
				$errors['guest_emails'] = __('One or more guest emails are invalid.', 'xdl-booking');
				break;
			}
			$guests[strtolower($g)] = $g;
		}
		if (count($guests) > self::MAX_GUESTS) {
			/* translators: %d: max guests */
			$errors['guest_emails'] = sprintf(__('You can add up to %d guest emails.', 'xdl-booking'), self::MAX_GUESTS);
		}

		$wedding = sanitize_text_field($p['wedding_date'] ?? '');
		if ('' !== $wedding && !XDL_Booking_Availability::is_valid_ymd($wedding)) {
			$errors['wedding_date'] = __('Please enter a valid wedding date.', 'xdl-booking');
		}

		$allowed  = XDL_Booking::services();
		$services = array_values(array_intersect($allowed, array_map('sanitize_text_field', (array) ($p['services'] ?? array()))));
		if ($allowed && !$services) {
			$errors['services'] = __('Please choose at least one service.', 'xdl-booking');
		}

		$note = sanitize_textarea_field($p['note'] ?? '');
		if (mb_strlen($note) > 2000) {
			$errors['note'] = __('Your note is too long.', 'xdl-booking');
		}

		if ($errors) {
			return new WP_Error('invalid', __('Please check the highlighted fields.', 'xdl-booking'), array('status' => 422, 'fields' => $errors));
		}

		return array(
			'booking_date'  => $date,
			'start_time'    => $time . ':00',
			'end_time'      => XDL_Booking_Availability::end_time($time) . ':00',
			'customer_name' => $name,
			'email'         => $email,
			'phone'         => $phone,
			'guest_emails'  => implode(', ', $guests),
			'wedding_date'  => '' !== $wedding ? $wedding : null,
			'services'      => implode(', ', $services),
			'note'          => $note,
			'status'        => XDL_Booking::STATUS_PENDING,
		);
	}

	private function error($code, $message, $status) {
		return new WP_Error('xdl_booking_' . $code, $message, array('status' => $status));
	}

	private function ip() {
		$ip = isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : '';
		return (string) apply_filters('xdl_booking_client_ip', filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '');
	}
}
