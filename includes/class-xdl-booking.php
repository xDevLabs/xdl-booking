<?php

if (!defined('ABSPATH')) {
	exit;
}

final class XDL_Booking {

	const OPTION        = 'xdl_booking_settings';
	const DB_VERSION    = '1.0.0';
	const DB_OPTION     = 'xdl_booking_db_version';

	const STATUS_PENDING   = 'pending';
	const STATUS_CONFIRMED = 'confirmed';
	const STATUS_COMPLETED = 'completed';
	const STATUS_CANCELLED = 'cancelled';

	private static $instance = null;

	public static function instance() {
		if (null === self::$instance) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public static function init() {
		load_plugin_textdomain('xdl-booking', false, dirname(plugin_basename(XDL_BOOKING_FILE)) . '/languages');
		self::instance()->boot();
	}

	public static function activate() {
		require_once XDL_BOOKING_DIR . 'includes/class-xdl-booking-db.php';
		XDL_Booking_DB::install();
		update_option(self::DB_OPTION, self::DB_VERSION);
		if (false === get_option(self::OPTION, false)) {
			add_option(self::OPTION, self::defaults());
		}
	}

	private function boot() {
		$files = array(
			'class-xdl-booking-db.php',
			'class-xdl-booking-availability.php',
			'class-xdl-booking-mailer.php',
			'class-xdl-booking-rest.php',
			'class-xdl-booking-frontend.php',
		);
		foreach ($files as $file) {
			require_once XDL_BOOKING_DIR . 'includes/' . $file;
		}

		if (get_option(self::DB_OPTION) !== self::DB_VERSION) {
			self::activate();
		}

		new XDL_Booking_REST();
		new XDL_Booking_Frontend();

		if (is_admin()) {
			require_once XDL_BOOKING_DIR . 'includes/class-xdl-booking-admin.php';
			new XDL_Booking_Admin();
		}

		add_action('init', array($this, 'maybe_register_bricks_element'), 11);
	}

	public function maybe_register_bricks_element() {
		if (!class_exists('\Bricks\Elements')) {
			return;
		}
		\Bricks\Elements::register_element(XDL_BOOKING_DIR . 'includes/class-xdl-booking-element.php', 'xdl-booking-form', 'XDL_Booking_Element');
	}

	public static function defaults() {
		return array(
			'working_days'     => array(1, 2, 3, 4, 5, 6, 7),
			'open_time'        => '09:00',
			'close_time'       => '18:00',
			'duration'         => 60,
			'buffer'           => 30,
			'interval'         => 30,
			'capacity'         => 1,
			'min_days_ahead'   => 0,
			'min_notice_hours' => 0,
			'max_days_ahead'   => 60,
			'blocked_dates'    => '',
			'services'         => "Try on at atelier\nBespoke design consultation\nRental consultation",
			'admin_emails'     => get_option('admin_email'),
			'customer_email'   => 1,
			'default_country'  => 'vn',
			'accent_color'     => '#1f1f1f',
			'success_message'  => '',
		);
	}

	public static function settings() {
		static $cache = null;
		if (null === $cache) {
			$cache = wp_parse_args((array) get_option(self::OPTION, array()), self::defaults());
		}
		return $cache;
	}

	public static function get($key) {
		$s = self::settings();
		return $s[$key] ?? null;
	}

	/** @return string[] */
	public static function services() {
		$lines = preg_split('/\r\n|\r|\n/', (string) self::get('services'));
		return array_values(array_filter(array_map('trim', $lines), 'strlen'));
	}

	/** @return string[] Y-m-d */
	public static function blocked_dates() {
		preg_match_all('/\d{4}-\d{2}-\d{2}/', (string) self::get('blocked_dates'), $m);
		return array_unique($m[0]);
	}

	/** @return string[] */
	public static function admin_emails() {
		$list = array_filter(array_map('trim', explode(',', (string) self::get('admin_emails'))), 'is_email');
		return $list ? array_values($list) : array(get_option('admin_email'));
	}

	public static function capability() {
		return apply_filters('xdl_booking_capability', 'edit_others_posts');
	}

	public static function statuses() {
		return array(
			self::STATUS_PENDING   => __('Pending', 'xdl-booking'),
			self::STATUS_CONFIRMED => __('Confirmed', 'xdl-booking'),
			self::STATUS_COMPLETED => __('Completed', 'xdl-booking'),
			self::STATUS_CANCELLED => __('Cancelled', 'xdl-booking'),
		);
	}

	public static function status_label($status) {
		$all = self::statuses();
		return $all[$status] ?? $status;
	}

	/** Statuses that occupy a time slot. */
	public static function blocking_statuses() {
		return apply_filters('xdl_booking_blocking_statuses', array(self::STATUS_PENDING, self::STATUS_CONFIRMED));
	}

	/** Site timezone (Settings → General); slots, "now" and stored timestamps use it. */
	public static function timezone() {
		return wp_timezone();
	}

	public static function now_mysql() {
		return (new DateTimeImmutable('now', self::timezone()))->format('Y-m-d H:i:s');
	}

	public static function format_date($ymd) {
		if (!$ymd || '0000-00-00' === $ymd) {
			return '';
		}
		$dt = DateTimeImmutable::createFromFormat('!Y-m-d', $ymd, self::timezone());
		return $dt ? wp_date(get_option('date_format'), $dt->getTimestamp(), self::timezone()) : $ymd;
	}

	public static function format_datetime($mysql) {
		$dt = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', (string) $mysql, self::timezone());
		return $dt ? wp_date(get_option('date_format') . ' ' . get_option('time_format'), $dt->getTimestamp(), self::timezone()) : (string) $mysql;
	}

	public static function format_time($his) {
		return substr((string) $his, 0, 5);
	}
}
