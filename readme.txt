=== XDL Booking ===
Requires at least: 6.4
Requires PHP: 8.0
Stable tag: 1.0.0
License: GPL-2.0-or-later

Standalone appointment booking: calendar, time slots with a gap after each booking, customer form, admin list/calendar and email notifications. No WooCommerce dependency.

== Usage ==
* Shortcode: [xdl_booking] or [xdl_booking title="Book an appointment"]
* Bricks: element "Booking Form (XDL Booking)" (registered only when Bricks is active)
* Settings: Bookings → Settings (working days, hours, duration, gap, slot step, capacity, notice, closed dates, services, admin emails, customer confirmation, default phone country, accent color)

== Slot rule ==
A start time is offered when fewer than "Bookings at the same time" existing bookings overlap it,
with each booking padded by "Gap after each booking" on both sides. Pending + confirmed bookings block slots.
The server re-checks availability under a MySQL named lock before saving, so a slot cannot be double-booked.
"Gap days": 0 = same-day booking allowed, 1 = earliest is tomorrow, etc. Past times and times inside "Minimum notice" (hours) are hidden and rejected. All times use the WordPress timezone (Settings → General).

== Theme override ==
Copy templates/booking-form.php to {theme}/xdl-booking/booking-form.php.
Colors: set --xdlb-accent (and other --xdlb-* CSS variables) on .xdlb.

== Hooks ==
Actions: xdl_booking_created( $id, $booking ), xdl_booking_status_changed( $id, $new, $old )
Filters: xdl_booking_capability, xdl_booking_blocking_statuses, xdl_booking_slots, xdl_booking_client_ip,
xdl_booking_admin_email_subject / _body, xdl_booking_customer_email_subject / _body

== REST ==
GET  /wp-json/xdl-booking/v1/month?month=YYYY-MM
GET  /wp-json/xdl-booking/v1/slots?date=YYYY-MM-DD
POST /wp-json/xdl-booking/v1/bookings

== Third-party ==
intl-tel-input 25.3.1 (MIT) bundled in assets/vendor/intl-tel-input.
