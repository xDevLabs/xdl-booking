<?php

if (!defined('ABSPATH')) {
	exit;
}

final class XDL_Booking_Frontend {

	public function __construct() {
		add_action('wp_enqueue_scripts', array($this, 'register_assets'));
		add_shortcode('xdl_booking', array($this, 'shortcode'));
	}

	public function register_assets() {
		$vendor = XDL_BOOKING_URL . 'assets/vendor/intl-tel-input/';
		wp_register_style('xdl-intl-tel-input', $vendor . 'css/intlTelInput.min.css', array(), '25.3.1');
		wp_register_script('xdl-intl-tel-input', $vendor . 'js/intlTelInputWithUtils.min.js', array(), '25.3.1', true);

		wp_register_style('xdl-booking', XDL_BOOKING_URL . 'assets/css/booking.css', array('xdl-intl-tel-input'), XDL_BOOKING_VERSION);
		wp_register_script('xdl-booking', XDL_BOOKING_URL . 'assets/js/booking.js', array('xdl-intl-tel-input'), XDL_BOOKING_VERSION, true);
	}

	public static function enqueue() {
		if (!wp_script_is('xdl-booking', 'registered')) {
			(new self())->register_assets();
		}
		wp_enqueue_style('xdl-booking');
		wp_enqueue_script('xdl-booking');

		static $localized = false;
		if ($localized) {
			return;
		}
		$localized = true;

		wp_localize_script('xdl-booking', 'XDLBooking', array(
			'api'            => esc_url_raw(rest_url(XDL_Booking_REST::NS . '/')),
			'locale'         => str_replace('_', '-', determine_locale()),
			'weekStart'      => (int) get_option('start_of_week', 1),
			'defaultCountry' => (string) XDL_Booking::get('default_country'),
			'i18n'           => array(
				'prevMonth'    => __('Previous month', 'xdl-booking'),
				'nextMonth'    => __('Next month', 'xdl-booking'),
				'noSlots'      => __('No available times on this day. Please choose another date.', 'xdl-booking'),
				'loading'      => __('Loading…', 'xdl-booking'),
				'submitting'   => __('Sending…', 'xdl-booking'),
				'loadError'    => __('Could not load availability. Please refresh the page.', 'xdl-booking'),
				'submitError'  => __('Could not submit your booking. Please try again.', 'xdl-booking'),
				'invalidPhone' => __('Please enter a valid phone number.', 'xdl-booking'),
				'pickSlot'     => __('Please choose a date and time.', 'xdl-booking'),
			),
		));
	}

	public function shortcode($atts = array()) {
		$atts = shortcode_atts(array(
			'title' => '',
		), $atts, 'xdl_booking');

		return self::render($atts);
	}

	public static function render(array $atts = array()) {
		self::enqueue();

		$services = XDL_Booking::services();
		$accent   = sanitize_hex_color((string) XDL_Booking::get('accent_color')) ?: '#1f1f1f';
		$title    = $atts['title'] ?? '';

		$template = locate_template('xdl-booking/booking-form.php') ?: XDL_BOOKING_DIR . 'templates/booking-form.php';

		ob_start();
		include $template;
		return ob_get_clean();
	}
}
