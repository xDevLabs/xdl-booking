<?php
/**
 * Plugin Name: XDL Booking
 * Plugin URI:  https://xdevlabs.com
 * Description: Appointment booking with calendar, time slots (with buffer between bookings), customer form, admin list/calendar views and email notifications. Use shortcode [xdl_booking] or the Bricks element.
 * Version: 1.0.0
 * Requires at least: 6.4
 * Requires PHP: 8.0
 * Author: xDevLabs
 * Author URI: https://xdevlabs.com
 * Text Domain: xdl-booking
 * Domain Path: /languages
 * License: GPL-2.0-or-later
 */

if (!defined('ABSPATH')) {
	exit;
}

define('XDL_BOOKING_VERSION', '1.0.0');
define('XDL_BOOKING_FILE', __FILE__);
define('XDL_BOOKING_DIR', plugin_dir_path(__FILE__));
define('XDL_BOOKING_URL', plugin_dir_url(__FILE__));

require_once XDL_BOOKING_DIR . 'includes/class-xdl-booking.php';

register_activation_hook(__FILE__, array('XDL_Booking', 'activate'));

add_action('plugins_loaded', array('XDL_Booking', 'init'));
