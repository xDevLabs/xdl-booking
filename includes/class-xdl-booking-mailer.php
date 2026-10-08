<?php

if (!defined('ABSPATH')) {
	exit;
}

final class XDL_Booking_Mailer {

	public static function booking_created($booking) {
		if (!$booking) {
			return;
		}
		self::send_admin($booking);
		if (XDL_Booking::get('customer_email')) {
			self::send_customer($booking);
		}
	}

	private static function send_admin($b) {
		$site = wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES);
		$subject = sprintf(
			/* translators: 1: site name, 2: customer name, 3: date/time */
			__('[%1$s] New booking: %2$s — %3$s', 'xdl-booking'),
			$site,
			$b->customer_name,
			self::when($b)
		);

		$url  = admin_url('admin.php?page=xdl-booking&action=view&id=' . (int) $b->id);
		$body = '<p>' . esc_html__('A new appointment has been booked.', 'xdl-booking') . '</p>'
			. self::details_table($b)
			. '<p><a href="' . esc_url($url) . '">' . esc_html__('View booking in admin', 'xdl-booking') . '</a></p>';

		$headers = array(
			'Content-Type: text/html; charset=UTF-8',
			sprintf('Reply-To: %s <%s>', self::header_safe($b->customer_name), $b->email),
		);

		wp_mail(
			XDL_Booking::admin_emails(),
			apply_filters('xdl_booking_admin_email_subject', $subject, $b),
			self::wrap(apply_filters('xdl_booking_admin_email_body', $body, $b)),
			$headers
		);
	}

	private static function send_customer($b) {
		$site    = wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES);
		$subject = sprintf(
			/* translators: 1: site name, 2: date/time */
			__('[%1$s] We received your booking for %2$s', 'xdl-booking'),
			$site,
			self::when($b)
		);

		$body = '<p>' . sprintf(
			/* translators: %s: customer name */
			esc_html__('Hi %s,', 'xdl-booking'),
			esc_html($b->customer_name)
		) . '</p>'
			. '<p>' . esc_html__('Thank you for booking an appointment with us. Here are the details of your request — we will contact you shortly to confirm.', 'xdl-booking') . '</p>'
			. self::details_table($b);

		$headers = array('Content-Type: text/html; charset=UTF-8');
		$admins  = XDL_Booking::admin_emails();
		$headers[] = 'Reply-To: ' . $admins[0];
		foreach (array_filter(array_map('trim', explode(',', (string) $b->guest_emails)), 'is_email') as $guest) {
			$headers[] = 'Cc: ' . $guest;
		}

		wp_mail(
			$b->email,
			apply_filters('xdl_booking_customer_email_subject', $subject, $b),
			self::wrap(apply_filters('xdl_booking_customer_email_body', $body, $b)),
			$headers
		);
	}

	private static function when($b) {
		return XDL_Booking::format_date($b->booking_date) . ' ' . XDL_Booking::format_time($b->start_time);
	}

	public static function details_table($b) {
		$rows = array(
			__('Appointment', 'xdl-booking')  => self::when($b) . ' – ' . XDL_Booking::format_time($b->end_time),
			__('Name', 'xdl-booking')         => $b->customer_name,
			__('Email', 'xdl-booking')        => $b->email,
			__('Phone', 'xdl-booking')        => $b->phone,
			__('Guest emails', 'xdl-booking') => $b->guest_emails,
			__('Wedding date', 'xdl-booking') => XDL_Booking::format_date($b->wedding_date),
			__('Services', 'xdl-booking')     => $b->services,
			__('Note', 'xdl-booking')         => $b->note,
		);

		$html = '<table cellpadding="8" cellspacing="0" style="border-collapse:collapse;width:100%;max-width:600px">';
		foreach ($rows as $label => $value) {
			if ('' === (string) $value) {
				continue;
			}
			$html .= '<tr><th align="left" style="border-bottom:1px solid #eee;width:160px;vertical-align:top">' . esc_html($label) . '</th>'
				. '<td style="border-bottom:1px solid #eee">' . nl2br(esc_html($value)) . '</td></tr>';
		}
		return $html . '</table>';
	}

	private static function wrap($inner) {
		return '<div style="font-family:-apple-system,BlinkMacSystemFont,Segoe UI,Roboto,Arial,sans-serif;font-size:14px;line-height:1.6;color:#222">' . $inner . '</div>';
	}

	private static function header_safe($value) {
		return trim(str_replace(array("\r", "\n", '<', '>', '"'), '', (string) $value));
	}
}
