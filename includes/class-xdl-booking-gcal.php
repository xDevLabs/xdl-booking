<?php

if (!defined('ABSPATH')) {
	exit;
}

/**
 * One-way sync of bookings to a Google Calendar using a service account.
 *
 * Calls run in WP-Cron so a slow or failing Google API never blocks a booking.
 * The calendar must be shared with the service account's email ("Make changes to events").
 */
final class XDL_Booking_GCal {

	const HOOK_SYNC    = 'xdl_booking_gcal_sync';
	const HOOK_DELETE  = 'xdl_booking_gcal_delete';
	const TOKEN_KEY    = 'xdl_booking_gcal_token';
	const ERROR_OPTION = 'xdl_booking_gcal_last_error';
	const SCOPE        = 'https://www.googleapis.com/auth/calendar.events';
	const API          = 'https://www.googleapis.com/calendar/v3/calendars/';
	const MAX_ATTEMPTS = 3;

	/** Google event colors per status (https://developers.google.com/calendar/api/v3/reference/colors). */
	const COLORS = array(
		XDL_Booking::STATUS_PENDING   => '5',
		XDL_Booking::STATUS_CONFIRMED => '10',
		XDL_Booking::STATUS_COMPLETED => '8',
	);

	public function __construct() {
		add_action('xdl_booking_created', array($this, 'queue_sync'));
		add_action('xdl_booking_status_changed', array($this, 'queue_sync'));
		add_action('xdl_booking_deleted', array($this, 'queue_delete'));
		add_action(self::HOOK_SYNC, array(__CLASS__, 'sync'), 10, 2);
		add_action(self::HOOK_DELETE, array(__CLASS__, 'delete'), 10, 2);
	}

	/* ---------- Config ---------- */

	public static function enabled() {
		return XDL_Booking::get('gcal_enabled') && self::calendar_id() && self::credentials();
	}

	public static function calendar_id() {
		return trim((string) XDL_Booking::get('gcal_calendar_id'));
	}

	/** Credentials from the XDL_BOOKING_GCAL_CREDENTIALS constant (wp-config.php) win over the saved setting. */
	public static function credentials_from_constant() {
		return defined('XDL_BOOKING_GCAL_CREDENTIALS') && XDL_BOOKING_GCAL_CREDENTIALS;
	}

	/** @return array{client_email: string, private_key: string, token_uri: string}|null */
	public static function credentials() {
		$raw = self::credentials_from_constant() ? XDL_BOOKING_GCAL_CREDENTIALS : XDL_Booking::get('gcal_credentials');
		return self::parse_credentials((string) $raw);
	}

	/** Extract only the fields we need from a service-account JSON key; null when unusable. */
	public static function parse_credentials($json) {
		$data = json_decode((string) $json, true);
		if (!is_array($data) || empty($data['client_email']) || empty($data['private_key']) || !is_email($data['client_email'])) {
			return null;
		}
		if (!openssl_pkey_get_private($data['private_key'])) {
			return null;
		}
		return array(
			'client_email' => $data['client_email'],
			'private_key'  => $data['private_key'],
			'token_uri'    => !empty($data['token_uri']) ? $data['token_uri'] : 'https://oauth2.googleapis.com/token',
		);
	}

	/* ---------- Queue ---------- */

	public function queue_sync($id) {
		if (self::enabled()) {
			wp_schedule_single_event(time(), self::HOOK_SYNC, array((int) $id, 1));
		}
	}

	public function queue_delete($booking) {
		if (self::enabled() && !empty($booking->gcal_event_id)) {
			wp_schedule_single_event(time(), self::HOOK_DELETE, array((string) $booking->gcal_event_id, 1));
		}
	}

	private static function retry($hook, $arg, $attempt, $error) {
		self::log_error($error);
		if ($attempt < self::MAX_ATTEMPTS) {
			wp_schedule_single_event(time() + 5 * MINUTE_IN_SECONDS * $attempt, $hook, array($arg, $attempt + 1));
		}
	}

	/* ---------- Jobs ---------- */

	public static function sync($id, $attempt = 1) {
		if (!self::enabled()) {
			return;
		}
		$b = XDL_Booking_DB::get($id);
		if (!$b) {
			return;
		}

		$event_id = (string) $b->gcal_event_id;

		if (XDL_Booking::STATUS_CANCELLED === $b->status) {
			if ('' === $event_id) {
				return;
			}
			$res = self::request('DELETE', 'events/' . rawurlencode($event_id));
			if (is_wp_error($res) && !in_array($res->get_error_data()['status'] ?? 0, array(404, 410), true)) {
				self::retry(self::HOOK_SYNC, (int) $id, $attempt, $res);
				return;
			}
			XDL_Booking_DB::set_gcal_event_id($id, '');
			return;
		}

		$event = self::event_body($b);
		$res   = '' !== $event_id ? self::request('PUT', 'events/' . rawurlencode($event_id), $event) : null;

		// No event yet, or it was removed from Google: create a fresh one.
		if (null === $res || (is_wp_error($res) && in_array($res->get_error_data()['status'] ?? 0, array(404, 410), true))) {
			$res = self::request('POST', 'events', $event);
		}
		if (is_wp_error($res)) {
			self::retry(self::HOOK_SYNC, (int) $id, $attempt, $res);
			return;
		}
		if (!empty($res['id']) && $res['id'] !== $event_id) {
			XDL_Booking_DB::set_gcal_event_id($id, $res['id']);
		}
		delete_option(self::ERROR_OPTION);
	}

	public static function delete($event_id, $attempt = 1) {
		if (!self::enabled() || '' === (string) $event_id) {
			return;
		}
		$res = self::request('DELETE', 'events/' . rawurlencode($event_id));
		if (is_wp_error($res) && !in_array($res->get_error_data()['status'] ?? 0, array(404, 410), true)) {
			self::retry(self::HOOK_DELETE, (string) $event_id, $attempt, $res);
		}
	}

	/** Checks that the token works and the calendar is writable by the service account. */
	public static function test_connection() {
		if (!self::calendar_id() || !self::credentials()) {
			return new WP_Error('xdlb_gcal_config', __('Enter a calendar ID and valid service account credentials first.', 'xdl-booking'));
		}
		delete_transient(self::TOKEN_KEY);
		$res = self::request('GET', 'events?maxResults=1');
		if (is_wp_error($res)) {
			self::log_error($res);
			return $res;
		}
		delete_option(self::ERROR_OPTION);
		return true;
	}

	/* ---------- Event ---------- */

	private static function event_body($b) {
		$tz    = XDL_Booking::timezone();
		$start = new DateTimeImmutable($b->booking_date . ' ' . $b->start_time, $tz);
		$end   = new DateTimeImmutable($b->booking_date . ' ' . $b->end_time, $tz);

		$summary = $b->customer_name . ($b->services ? ' — ' . str_replace("\n", ', ', $b->services) : '');
		if (XDL_Booking::STATUS_PENDING === $b->status) {
			/* translators: %s: event title */
			$summary = sprintf(__('[Pending] %s', 'xdl-booking'), $summary);
		}

		$lines = array(
			__('Name', 'xdl-booking')         => $b->customer_name,
			__('Email', 'xdl-booking')        => $b->email,
			__('Phone', 'xdl-booking')        => $b->phone,
			__('Guest emails', 'xdl-booking') => $b->guest_emails,
			__('Wedding date', 'xdl-booking') => XDL_Booking::format_date($b->wedding_date),
			__('Services', 'xdl-booking')     => $b->services,
			__('Note', 'xdl-booking')         => $b->note,
			__('Status', 'xdl-booking')       => XDL_Booking::status_label($b->status),
		);
		$description = '';
		foreach ($lines as $label => $value) {
			if ('' !== (string) $value) {
				$description .= $label . ': ' . $value . "\n";
			}
		}
		$description .= "\n" . admin_url('admin.php?page=xdl-booking&action=view&id=' . (int) $b->id);

		$event = array(
			'summary'            => $summary,
			'description'        => $description,
			'start'              => array('dateTime' => $start->format(DATE_RFC3339)),
			'end'                => array('dateTime' => $end->format(DATE_RFC3339)),
			'extendedProperties' => array('private' => array('xdl_booking_id' => (string) $b->id)),
		);
		if (isset(self::COLORS[$b->status])) {
			$event['colorId'] = self::COLORS[$b->status];
		}

		return apply_filters('xdl_booking_gcal_event', $event, $b);
	}

	/* ---------- HTTP ---------- */

	/** @return array|WP_Error Decoded JSON body; error data carries the HTTP status. */
	private static function request($method, $path, $body = null) {
		$token = self::access_token();
		if (is_wp_error($token)) {
			return $token;
		}

		$args = array(
			'method'  => $method,
			'timeout' => 15,
			'headers' => array(
				'Authorization' => 'Bearer ' . $token,
				'Content-Type'  => 'application/json',
			),
		);
		if (null !== $body) {
			$args['body'] = wp_json_encode($body);
		}

		$res = wp_remote_request(self::API . rawurlencode(self::calendar_id()) . '/' . $path, $args);
		if (is_wp_error($res)) {
			return $res;
		}

		$code = (int) wp_remote_retrieve_response_code($res);
		$data = json_decode(wp_remote_retrieve_body($res), true);
		if ($code >= 300) {
			$message = $data['error']['message'] ?? wp_remote_retrieve_response_message($res);
			return new WP_Error('xdlb_gcal_http', sprintf('Google Calendar API %d: %s', $code, $message), array('status' => $code));
		}
		return is_array($data) ? $data : array();
	}

	/** OAuth 2.0 JWT bearer flow for service accounts; token cached until shortly before expiry. */
	private static function access_token() {
		$cached = get_transient(self::TOKEN_KEY);
		if ($cached) {
			return $cached;
		}

		$creds = self::credentials();
		if (!$creds) {
			return new WP_Error('xdlb_gcal_creds', __('Google Calendar credentials are missing or invalid.', 'xdl-booking'));
		}

		$now    = time();
		$header = self::b64url(wp_json_encode(array('alg' => 'RS256', 'typ' => 'JWT')));
		$claims = self::b64url(wp_json_encode(array(
			'iss'   => $creds['client_email'],
			'scope' => self::SCOPE,
			'aud'   => $creds['token_uri'],
			'iat'   => $now,
			'exp'   => $now + HOUR_IN_SECONDS,
		)));
		if (!openssl_sign($header . '.' . $claims, $signature, $creds['private_key'], OPENSSL_ALGO_SHA256)) {
			return new WP_Error('xdlb_gcal_sign', __('Could not sign the Google token request. Check the private key.', 'xdl-booking'));
		}

		$res = wp_remote_post($creds['token_uri'], array(
			'timeout' => 15,
			'body'    => array(
				'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
				'assertion'  => $header . '.' . $claims . '.' . self::b64url($signature),
			),
		));
		if (is_wp_error($res)) {
			return $res;
		}

		$data = json_decode(wp_remote_retrieve_body($res), true);
		if (empty($data['access_token'])) {
			$message = $data['error_description'] ?? ($data['error'] ?? wp_remote_retrieve_response_message($res));
			return new WP_Error('xdlb_gcal_token', 'Google token: ' . $message, array('status' => (int) wp_remote_retrieve_response_code($res)));
		}

		set_transient(self::TOKEN_KEY, $data['access_token'], max(60, (int) ($data['expires_in'] ?? 3600) - 120));
		return $data['access_token'];
	}

	private static function b64url($raw) {
		return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
	}

	private static function log_error(WP_Error $error) {
		update_option(self::ERROR_OPTION, array(
			'message' => $error->get_error_message(),
			'time'    => XDL_Booking::now_mysql(),
		), false);
		if (defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) {
			error_log('[xdl-booking] ' . $error->get_error_message()); // phpcs:ignore WordPress.PHP.DevelopmentFunctions
		}
	}
}
