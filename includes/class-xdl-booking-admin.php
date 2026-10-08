<?php

if (!defined('ABSPATH')) {
	exit;
}

final class XDL_Booking_Admin {

	const SLUG          = 'xdl-booking';
	const SLUG_CALENDAR = 'xdl-booking-calendar';
	const SLUG_SETTINGS = 'xdl-booking-settings';

	private $list_hook = '';

	public function __construct() {
		add_action('admin_menu', array($this, 'menus'));
		add_action('admin_init', array($this, 'register_settings'));
		add_action('admin_enqueue_scripts', array($this, 'assets'));
		add_action('admin_post_xdl_booking_status', array($this, 'handle_status'));
		add_action('admin_post_xdl_booking_delete', array($this, 'handle_delete'));
		add_filter('set-screen-option', array($this, 'save_screen_option'), 10, 3);
		add_filter('plugin_action_links_' . plugin_basename(XDL_BOOKING_FILE), array($this, 'plugin_links'));
	}

	/* ---------- URLs & helpers ---------- */

	public static function view_url($id) {
		return admin_url('admin.php?page=' . self::SLUG . '&action=view&id=' . (int) $id);
	}

	public static function status_url($id, $status) {
		return wp_nonce_url(
			admin_url('admin-post.php?action=xdl_booking_status&id=' . (int) $id . '&status=' . $status),
			'xdl_booking_status_' . (int) $id
		);
	}

	public static function delete_url($id) {
		return wp_nonce_url(admin_url('admin-post.php?action=xdl_booking_delete&id=' . (int) $id), 'xdl_booking_delete_' . (int) $id);
	}

	public static function status_badge($status) {
		return sprintf('<span class="xdlb-badge xdlb-badge--%s">%s</span>', esc_attr($status), esc_html(XDL_Booking::status_label($status)));
	}

	private function back_url() {
		$ref = wp_get_referer();
		return $ref ?: admin_url('admin.php?page=' . self::SLUG);
	}

	public function plugin_links($links) {
		array_unshift($links, '<a href="' . esc_url(admin_url('admin.php?page=' . self::SLUG_SETTINGS)) . '">' . esc_html__('Settings', 'xdl-booking') . '</a>');
		return $links;
	}

	/* ---------- Menu ---------- */

	public function menus() {
		$cap     = XDL_Booking::capability();
		$counts  = XDL_Booking_DB::count_by_status();
		$pending = $counts[XDL_Booking::STATUS_PENDING] ?? 0;
		$bubble  = $pending ? sprintf(' <span class="awaiting-mod">%d</span>', $pending) : '';

		$this->list_hook = add_menu_page(
			__('Bookings', 'xdl-booking'),
			__('Bookings', 'xdl-booking') . $bubble,
			$cap,
			self::SLUG,
			array($this, 'render_list'),
			'dashicons-calendar-alt',
			26
		);
		add_submenu_page(self::SLUG, __('All bookings', 'xdl-booking'), __('All bookings', 'xdl-booking'), $cap, self::SLUG, array($this, 'render_list'));
		add_submenu_page(self::SLUG, __('Booking calendar', 'xdl-booking'), __('Calendar', 'xdl-booking'), $cap, self::SLUG_CALENDAR, array($this, 'render_calendar'));
		add_submenu_page(self::SLUG, __('Booking settings', 'xdl-booking'), __('Settings', 'xdl-booking'), 'manage_options', self::SLUG_SETTINGS, array($this, 'render_settings'));

		add_action('load-' . $this->list_hook, array($this, 'load_list'));
	}

	public function assets($hook) {
		if (false === strpos($hook, self::SLUG)) {
			return;
		}
		wp_enqueue_style('xdl-booking-admin', XDL_BOOKING_URL . 'assets/css/admin.css', array(), XDL_BOOKING_VERSION);
	}

	public function save_screen_option($status, $option, $value) {
		return 'xdl_booking_per_page' === $option ? min(200, max(1, (int) $value)) : $status;
	}

	/* ---------- Actions ---------- */

	public function load_list() {
		require_once XDL_BOOKING_DIR . 'includes/class-xdl-booking-list-table.php';

		add_screen_option('per_page', array(
			'label'   => __('Bookings per page', 'xdl-booking'),
			'default' => 20,
			'option'  => 'xdl_booking_per_page',
		));

		$table  = new XDL_Booking_List_Table();
		$action = $table->current_action();
		if (!$action || empty($_REQUEST['ids'])) {
			return;
		}

		check_admin_referer('bulk-bookings');
		$ids  = array_map('absint', (array) $_REQUEST['ids']);
		$done = 0;

		if ('delete' === $action) {
			foreach ($ids as $id) {
				$done += XDL_Booking_DB::delete($id) ? 1 : 0;
			}
		} elseif (0 === strpos($action, 'mark_')) {
			$status = substr($action, 5);
			foreach ($ids as $id) {
				$done += XDL_Booking_DB::update_status($id, $status) ? 1 : 0;
			}
		}

		wp_safe_redirect(add_query_arg('xdlb_updated', $done, remove_query_arg(array('action', 'action2', 'ids', '_wpnonce', '_wp_http_referer'), wp_get_referer() ?: admin_url('admin.php?page=' . self::SLUG))));
		exit;
	}

	public function handle_status() {
		$id = absint($_GET['id'] ?? 0);
		if (!current_user_can(XDL_Booking::capability())) {
			wp_die(esc_html__('You are not allowed to do this.', 'xdl-booking'), 403);
		}
		check_admin_referer('xdl_booking_status_' . $id);
		$ok = XDL_Booking_DB::update_status($id, sanitize_key($_GET['status'] ?? ''));
		wp_safe_redirect(add_query_arg('xdlb_updated', $ok ? 1 : 0, $this->back_url()));
		exit;
	}

	public function handle_delete() {
		$id = absint($_GET['id'] ?? 0);
		if (!current_user_can(XDL_Booking::capability())) {
			wp_die(esc_html__('You are not allowed to do this.', 'xdl-booking'), 403);
		}
		check_admin_referer('xdl_booking_delete_' . $id);
		XDL_Booking_DB::delete($id);
		wp_safe_redirect(add_query_arg('xdlb_deleted', 1, admin_url('admin.php?page=' . self::SLUG)));
		exit;
	}

	private function notices() {
		if (isset($_GET['xdlb_updated'])) {
			$n = absint($_GET['xdlb_updated']);
			/* translators: %d: number of bookings */
			printf('<div class="notice notice-success is-dismissible"><p>%s</p></div>', esc_html(sprintf(_n('%d booking updated.', '%d bookings updated.', $n, 'xdl-booking'), $n)));
		}
		if (isset($_GET['xdlb_deleted'])) {
			printf('<div class="notice notice-success is-dismissible"><p>%s</p></div>', esc_html__('Booking deleted.', 'xdl-booking'));
		}
	}

	/* ---------- List & detail ---------- */

	public function render_list() {
		if ('view' === ($_GET['action'] ?? '') && !empty($_GET['id'])) {
			$this->render_detail(absint($_GET['id']));
			return;
		}

		$table = new XDL_Booking_List_Table();
		$table->prepare_items();
		?>
		<div class="wrap">
			<h1 class="wp-heading-inline"><?php esc_html_e('Bookings', 'xdl-booking'); ?></h1>
			<a href="<?php echo esc_url(admin_url('admin.php?page=' . self::SLUG_CALENDAR)); ?>" class="page-title-action"><?php esc_html_e('Calendar view', 'xdl-booking'); ?></a>
			<hr class="wp-header-end">
			<?php $this->notices(); ?>
			<?php $table->views(); ?>
			<form method="get">
				<input type="hidden" name="page" value="<?php echo esc_attr(self::SLUG); ?>">
				<?php if (!empty($_GET['status'])) : ?>
					<input type="hidden" name="status" value="<?php echo esc_attr(sanitize_key($_GET['status'])); ?>">
				<?php endif; ?>
				<?php $table->search_box(__('Search bookings', 'xdl-booking'), 'xdlb-search'); ?>
				<?php $table->display(); ?>
			</form>
		</div>
		<?php
	}

	private function render_detail($id) {
		$b = XDL_Booking_DB::get($id);
		if (!$b) {
			echo '<div class="wrap"><h1>' . esc_html__('Booking not found', 'xdl-booking') . '</h1></div>';
			return;
		}
		$list_url = admin_url('admin.php?page=' . self::SLUG);
		$cal_url  = admin_url('admin.php?page=' . self::SLUG_CALENDAR . '&month=' . substr($b->booking_date, 0, 7));
		?>
		<div class="wrap xdlb-detail">
			<h1 class="wp-heading-inline">
				<?php
				/* translators: %d: booking ID */
				echo esc_html(sprintf(__('Booking #%d', 'xdl-booking'), $b->id));
				?>
			</h1>
			<?php echo self::status_badge($b->status); // phpcs:ignore WordPress.Security.EscapeOutput ?>
			<hr class="wp-header-end">
			<?php $this->notices(); ?>
			<p>
				<a href="<?php echo esc_url($list_url); ?>">&larr; <?php esc_html_e('All bookings', 'xdl-booking'); ?></a>
				&nbsp;|&nbsp;
				<a href="<?php echo esc_url($cal_url); ?>"><?php esc_html_e('Show in calendar', 'xdl-booking'); ?></a>
			</p>

			<div class="xdlb-detail-grid">
				<div class="postbox">
					<div class="inside">
						<?php echo XDL_Booking_Mailer::details_table($b); // phpcs:ignore WordPress.Security.EscapeOutput ?>
						<p class="description">
							<?php
							/* translators: 1: created date, 2: IP */
							echo esc_html(sprintf(__('Submitted %1$s from IP %2$s', 'xdl-booking'), date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($b->created_at)), $b->ip ?: '—'));
							?>
						</p>
					</div>
				</div>
				<div class="postbox">
					<h2 class="hndle"><?php esc_html_e('Actions', 'xdl-booking'); ?></h2>
					<div class="inside xdlb-actions">
						<?php foreach (XDL_Booking::statuses() as $key => $label) : ?>
							<?php if ($key !== $b->status) : ?>
								<a class="button" href="<?php echo esc_url(self::status_url($b->id, $key)); ?>">
									<?php
									/* translators: %s: status */
									echo esc_html(sprintf(__('Mark as: %s', 'xdl-booking'), $label));
									?>
								</a>
							<?php endif; ?>
						<?php endforeach; ?>
						<a class="button" href="mailto:<?php echo esc_attr($b->email); ?>"><?php esc_html_e('Email customer', 'xdl-booking'); ?></a>
						<a class="button-link-delete" href="<?php echo esc_url(self::delete_url($b->id)); ?>" onclick="return confirm('<?php echo esc_js(__('Delete this booking permanently?', 'xdl-booking')); ?>');"><?php esc_html_e('Delete booking', 'xdl-booking'); ?></a>
					</div>
				</div>
			</div>
		</div>
		<?php
	}

	/* ---------- Calendar ---------- */

	public function render_calendar() {
		$tz    = wp_timezone();
		$month = sanitize_text_field($_GET['month'] ?? '');
		$first = preg_match('/^\d{4}-\d{2}$/', $month) ? DateTimeImmutable::createFromFormat('!Y-m-d', $month . '-01', $tz) : false;
		if (!$first) {
			$first = (new DateTimeImmutable('first day of this month', $tz))->setTime(0, 0);
		}
		$last       = $first->modify('last day of this month');
		$week_start = (int) get_option('start_of_week', 1);
		$lead       = ((int) $first->format('w') - $week_start + 7) % 7;
		$grid_start = $first->modify("-{$lead} days");
		$cells      = (int) ceil(($lead + (int) $last->format('j')) / 7) * 7;
		$grid_end   = $grid_start->modify('+' . ($cells - 1) . ' days');
		$today      = (new DateTimeImmutable('now', $tz))->format('Y-m-d');
		$show_cancelled = !empty($_GET['cancelled']);

		$by_date = array();
		foreach (XDL_Booking_DB::between($grid_start->format('Y-m-d'), $grid_end->format('Y-m-d')) as $row) {
			if (!$show_cancelled && XDL_Booking::STATUS_CANCELLED === $row->status) {
				continue;
			}
			$by_date[$row->booking_date][] = $row;
		}

		$base = admin_url('admin.php?page=' . self::SLUG_CALENDAR . ($show_cancelled ? '&cancelled=1' : ''));
		$prev = add_query_arg('month', $first->modify('-1 month')->format('Y-m'), $base);
		$next = add_query_arg('month', $first->modify('+1 month')->format('Y-m'), $base);
		$blocked = XDL_Booking::blocked_dates();
		$working = array_map('intval', (array) XDL_Booking::get('working_days'));
		?>
		<div class="wrap xdlb-calendar-wrap">
			<h1 class="wp-heading-inline"><?php esc_html_e('Booking calendar', 'xdl-booking'); ?></h1>
			<a href="<?php echo esc_url(admin_url('admin.php?page=' . self::SLUG)); ?>" class="page-title-action"><?php esc_html_e('List view', 'xdl-booking'); ?></a>
			<hr class="wp-header-end">

			<div class="xdlb-cal-toolbar">
				<div>
					<a class="button" href="<?php echo esc_url($prev); ?>" aria-label="<?php esc_attr_e('Previous month', 'xdl-booking'); ?>">&lsaquo;</a>
					<a class="button" href="<?php echo esc_url(remove_query_arg('month', $base)); ?>"><?php esc_html_e('Today', 'xdl-booking'); ?></a>
					<a class="button" href="<?php echo esc_url($next); ?>" aria-label="<?php esc_attr_e('Next month', 'xdl-booking'); ?>">&rsaquo;</a>
				</div>
				<h2><?php echo esc_html(wp_date('F Y', $first->getTimestamp())); ?></h2>
				<div class="xdlb-legend">
					<?php foreach (XDL_Booking::statuses() as $key => $label) : ?>
						<?php echo self::status_badge($key); // phpcs:ignore WordPress.Security.EscapeOutput ?>
					<?php endforeach; ?>
					<a href="<?php echo esc_url(add_query_arg(array('month' => $first->format('Y-m'), 'cancelled' => $show_cancelled ? 0 : 1), admin_url('admin.php?page=' . self::SLUG_CALENDAR))); ?>">
						<?php $show_cancelled ? esc_html_e('Hide cancelled', 'xdl-booking') : esc_html_e('Show cancelled', 'xdl-booking'); ?>
					</a>
				</div>
			</div>

			<div class="xdlb-cal">
				<?php for ($i = 0; $i < 7; $i++) : ?>
					<div class="xdlb-cal-wd"><?php echo esc_html($GLOBALS['wp_locale']->get_weekday_abbrev($GLOBALS['wp_locale']->get_weekday(($week_start + $i) % 7))); ?></div>
				<?php endfor; ?>

				<?php
				for ($i = 0; $i < $cells; $i++) :
					$day = $grid_start->modify("+{$i} days");
					$ymd = $day->format('Y-m-d');
					$classes = array('xdlb-cal-day');
					if ($day->format('m') !== $first->format('m')) {
						$classes[] = 'is-outside';
					}
					if ($ymd === $today) {
						$classes[] = 'is-today';
					}
					if (in_array($ymd, $blocked, true) || !in_array((int) $day->format('N'), $working, true)) {
						$classes[] = 'is-closed';
					}
					?>
					<div class="<?php echo esc_attr(implode(' ', $classes)); ?>">
						<div class="xdlb-cal-num">
							<a href="<?php echo esc_url(admin_url('admin.php?page=' . self::SLUG . '&date_from=' . $ymd . '&date_to=' . $ymd . '&order=asc')); ?>"><?php echo esc_html($day->format('j')); ?></a>
						</div>
						<?php foreach ($by_date[$ymd] ?? array() as $b) : ?>
							<a class="xdlb-cal-event xdlb-cal-event--<?php echo esc_attr($b->status); ?>" href="<?php echo esc_url(self::view_url($b->id)); ?>" title="<?php echo esc_attr($b->customer_name . ' · ' . $b->services); ?>">
								<strong><?php echo esc_html(XDL_Booking::format_time($b->start_time)); ?></strong>
								<?php echo esc_html($b->customer_name); ?>
							</a>
						<?php endforeach; ?>
					</div>
				<?php endfor; ?>
			</div>
		</div>
		<?php
	}

	/* ---------- Settings ---------- */

	public function register_settings() {
		register_setting('xdl_booking_settings', XDL_Booking::OPTION, array(
			'type'              => 'array',
			'sanitize_callback' => array($this, 'sanitize'),
		));
	}

	public function sanitize($in) {
		$d   = XDL_Booking::defaults();
		$in  = (array) $in;
		$out = array();

		$days = array_values(array_intersect(array(1, 2, 3, 4, 5, 6, 7), array_map('intval', (array) ($in['working_days'] ?? array()))));
		$out['working_days'] = $days;

		foreach (array('open_time', 'close_time') as $k) {
			$v       = sanitize_text_field($in[$k] ?? '');
			$out[$k] = preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $v) ? $v : $d[$k];
		}
		if ($out['close_time'] <= $out['open_time']) {
			add_settings_error(XDL_Booking::OPTION, 'hours', __('Closing time must be after opening time. Hours were reset to defaults.', 'xdl-booking'));
			$out['open_time']  = $d['open_time'];
			$out['close_time'] = $d['close_time'];
		}

		$ints = array(
			'duration'         => array(5, 720),
			'buffer'           => array(0, 240),
			'interval'         => array(5, 240),
			'capacity'         => array(1, 50),
			'min_notice_hours' => array(0, 720),
			'max_days_ahead'   => array(1, 730),
		);
		foreach ($ints as $k => [$min, $max]) {
			$v       = isset($in[$k]) ? (int) $in[$k] : $d[$k];
			$out[$k] = min($max, max($min, $v));
		}

		$out['blocked_dates']   = implode("\n", array_filter(array_map('trim', preg_split('/\r\n|\r|\n|,/', (string) ($in['blocked_dates'] ?? ''))), array('XDL_Booking_Availability', 'is_valid_ymd')));
		$out['services']        = implode("\n", array_filter(array_map('sanitize_text_field', preg_split('/\r\n|\r|\n/', (string) ($in['services'] ?? ''))), 'strlen'));
		$out['admin_emails']    = implode(', ', array_filter(array_map('sanitize_email', explode(',', (string) ($in['admin_emails'] ?? ''))), 'is_email'));
		$out['customer_email']  = empty($in['customer_email']) ? 0 : 1;
		$country                = strtolower(sanitize_text_field($in['default_country'] ?? ''));
		$out['default_country'] = preg_match('/^[a-z]{2}$/', $country) ? $country : $d['default_country'];
		$out['accent_color']    = sanitize_hex_color($in['accent_color'] ?? '') ?: $d['accent_color'];
		$out['success_message'] = sanitize_textarea_field($in['success_message'] ?? '');

		return $out;
	}

	public function render_settings() {
		if (!current_user_can('manage_options')) {
			return;
		}
		$s    = XDL_Booking::settings();
		$name = XDL_Booking::OPTION;
		$days = array(1 => __('Monday', 'xdl-booking'), 2 => __('Tuesday', 'xdl-booking'), 3 => __('Wednesday', 'xdl-booking'), 4 => __('Thursday', 'xdl-booking'), 5 => __('Friday', 'xdl-booking'), 6 => __('Saturday', 'xdl-booking'), 7 => __('Sunday', 'xdl-booking'));

		$number = function ($key, $min, $max, $suffix) use ($s, $name) {
			printf(
				'<input type="number" id="xdlb-%1$s" name="%2$s[%1$s]" value="%3$d" min="%4$d" max="%5$d" class="small-text"> %6$s',
				esc_attr($key),
				esc_attr($name),
				(int) $s[$key],
				$min,
				$max,
				esc_html($suffix)
			);
		};
		?>
		<div class="wrap">
			<h1><?php esc_html_e('Booking settings', 'xdl-booking'); ?></h1>
			<?php settings_errors(XDL_Booking::OPTION); ?>

			<div class="notice notice-info inline">
				<p>
					<?php esc_html_e('Add the booking form to any page with the shortcode', 'xdl-booking'); ?>
					<code>[xdl_booking]</code>
					<?php esc_html_e('or the "Booking Form (XDL Booking)" Bricks element.', 'xdl-booking'); ?>
				</p>
			</div>

			<form method="post" action="options.php">
				<?php settings_fields('xdl_booking_settings'); ?>

				<h2 class="title"><?php esc_html_e('Schedule', 'xdl-booking'); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e('Working days', 'xdl-booking'); ?></th>
						<td>
							<fieldset>
								<?php foreach ($days as $n => $label) : ?>
									<label style="margin-right:12px">
										<input type="checkbox" name="<?php echo esc_attr($name); ?>[working_days][]" value="<?php echo (int) $n; ?>" <?php checked(in_array($n, array_map('intval', (array) $s['working_days']), true)); ?>>
										<?php echo esc_html($label); ?>
									</label>
								<?php endforeach; ?>
							</fieldset>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="xdlb-open_time"><?php esc_html_e('Opening hours', 'xdl-booking'); ?></label></th>
						<td>
							<input type="time" id="xdlb-open_time" name="<?php echo esc_attr($name); ?>[open_time]" value="<?php echo esc_attr($s['open_time']); ?>">
							&ndash;
							<input type="time" name="<?php echo esc_attr($name); ?>[close_time]" value="<?php echo esc_attr($s['close_time']); ?>" aria-label="<?php esc_attr_e('Closing time', 'xdl-booking'); ?>">
							<p class="description"><?php esc_html_e('The last appointment must end by closing time.', 'xdl-booking'); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="xdlb-duration"><?php esc_html_e('Appointment duration', 'xdl-booking'); ?></label></th>
						<td><?php $number('duration', 5, 720, __('minutes', 'xdl-booking')); ?></td>
					</tr>
					<tr>
						<th scope="row"><label for="xdlb-buffer"><?php esc_html_e('Gap after each booking', 'xdl-booking'); ?></label></th>
						<td>
							<?php $number('buffer', 0, 240, __('minutes', 'xdl-booking')); ?>
							<p class="description"><?php esc_html_e('Time kept free after every booking before another appointment can start (default 30).', 'xdl-booking'); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="xdlb-interval"><?php esc_html_e('Slot step', 'xdl-booking'); ?></label></th>
						<td>
							<?php $number('interval', 5, 240, __('minutes', 'xdl-booking')); ?>
							<p class="description"><?php esc_html_e('How often start times are offered, e.g. 30 → 09:00, 09:30, 10:00…', 'xdl-booking'); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="xdlb-capacity"><?php esc_html_e('Bookings at the same time', 'xdl-booking'); ?></label></th>
						<td>
							<?php $number('capacity', 1, 50, ''); ?>
							<p class="description"><?php esc_html_e('Number of customers that can be served in parallel (e.g. fitting rooms or staff).', 'xdl-booking'); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="xdlb-min_notice_hours"><?php esc_html_e('Minimum notice', 'xdl-booking'); ?></label></th>
						<td><?php $number('min_notice_hours', 0, 720, __('hours before the appointment', 'xdl-booking')); ?></td>
					</tr>
					<tr>
						<th scope="row"><label for="xdlb-max_days_ahead"><?php esc_html_e('Book up to', 'xdl-booking'); ?></label></th>
						<td><?php $number('max_days_ahead', 1, 730, __('days in advance', 'xdl-booking')); ?></td>
					</tr>
					<tr>
						<th scope="row"><label for="xdlb-blocked"><?php esc_html_e('Closed dates', 'xdl-booking'); ?></label></th>
						<td>
							<textarea id="xdlb-blocked" name="<?php echo esc_attr($name); ?>[blocked_dates]" rows="4" class="regular-text code" placeholder="2026-12-25"><?php echo esc_textarea($s['blocked_dates']); ?></textarea>
							<p class="description"><?php esc_html_e('One date per line (YYYY-MM-DD) — holidays, days off.', 'xdl-booking'); ?></p>
						</td>
					</tr>
				</table>

				<h2 class="title"><?php esc_html_e('Form', 'xdl-booking'); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="xdlb-services"><?php esc_html_e('Services', 'xdl-booking'); ?></label></th>
						<td>
							<textarea id="xdlb-services" name="<?php echo esc_attr($name); ?>[services]" rows="5" class="large-text"><?php echo esc_textarea($s['services']); ?></textarea>
							<p class="description"><?php esc_html_e('One service per line. Leave empty to hide the services field.', 'xdl-booking'); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="xdlb-country"><?php esc_html_e('Default phone country', 'xdl-booking'); ?></label></th>
						<td>
							<input type="text" id="xdlb-country" name="<?php echo esc_attr($name); ?>[default_country]" value="<?php echo esc_attr($s['default_country']); ?>" class="small-text" maxlength="2">
							<p class="description"><?php esc_html_e('Two-letter country code, e.g. vn, us, fr.', 'xdl-booking'); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="xdlb-accent"><?php esc_html_e('Accent color', 'xdl-booking'); ?></label></th>
						<td><input type="color" id="xdlb-accent" name="<?php echo esc_attr($name); ?>[accent_color]" value="<?php echo esc_attr($s['accent_color']); ?>"></td>
					</tr>
					<tr>
						<th scope="row"><label for="xdlb-success"><?php esc_html_e('Success message', 'xdl-booking'); ?></label></th>
						<td>
							<textarea id="xdlb-success" name="<?php echo esc_attr($name); ?>[success_message]" rows="3" class="large-text"><?php echo esc_textarea($s['success_message']); ?></textarea>
							<p class="description"><?php esc_html_e('Shown after a successful booking. Leave empty for the default message.', 'xdl-booking'); ?></p>
						</td>
					</tr>
				</table>

				<h2 class="title"><?php esc_html_e('Notifications', 'xdl-booking'); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="xdlb-admin-emails"><?php esc_html_e('Admin emails', 'xdl-booking'); ?></label></th>
						<td>
							<input type="text" id="xdlb-admin-emails" name="<?php echo esc_attr($name); ?>[admin_emails]" value="<?php echo esc_attr($s['admin_emails']); ?>" class="regular-text">
							<p class="description"><?php esc_html_e('Comma-separated. These addresses receive every new booking.', 'xdl-booking'); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e('Customer confirmation', 'xdl-booking'); ?></th>
						<td>
							<label>
								<input type="checkbox" name="<?php echo esc_attr($name); ?>[customer_email]" value="1" <?php checked(1, (int) $s['customer_email']); ?>>
								<?php esc_html_e('Email the customer (and CC guest emails) when a booking is received', 'xdl-booking'); ?>
							</label>
						</td>
					</tr>
				</table>

				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}
}
