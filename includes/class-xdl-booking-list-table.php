<?php

if (!defined('ABSPATH')) {
	exit;
}

if (!class_exists('WP_List_Table')) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

class XDL_Booking_List_Table extends WP_List_Table {

	public function __construct() {
		parent::__construct(array(
			'singular' => 'booking',
			'plural'   => 'bookings',
			'ajax'     => false,
		));
	}

	public function get_columns() {
		return array(
			'cb'           => '<input type="checkbox" />',
			'customer'     => __('Customer', 'xdl-booking'),
			'booking_date' => __('Appointment', 'xdl-booking'),
			'services'     => __('Services', 'xdl-booking'),
			'wedding_date' => __('Wedding date', 'xdl-booking'),
			'status'       => __('Status', 'xdl-booking'),
			'created_at'   => __('Booked on', 'xdl-booking'),
		);
	}

	protected function get_sortable_columns() {
		return array(
			'customer'     => array('customer_name', false),
			'booking_date' => array('booking_date', true),
			'wedding_date' => array('wedding_date', false),
			'status'       => array('status', false),
			'created_at'   => array('created_at', true),
		);
	}

	protected function get_bulk_actions() {
		$actions = array();
		foreach (XDL_Booking::statuses() as $key => $label) {
			/* translators: %s: status */
			$actions['mark_' . $key] = sprintf(__('Mark as: %s', 'xdl-booking'), $label);
		}
		$actions['delete'] = __('Delete', 'xdl-booking');
		return $actions;
	}

	protected function get_views() {
		$counts  = XDL_Booking_DB::count_by_status();
		$current = sanitize_key($_GET['status'] ?? '');
		$base    = admin_url('admin.php?page=xdl-booking');
		$views   = array(
			'all' => sprintf(
				'<a href="%s"%s>%s <span class="count">(%d)</span></a>',
				esc_url($base),
				'' === $current ? ' class="current"' : '',
				esc_html__('All', 'xdl-booking'),
				array_sum($counts)
			),
		);
		foreach (XDL_Booking::statuses() as $key => $label) {
			$views[$key] = sprintf(
				'<a href="%s"%s>%s <span class="count">(%d)</span></a>',
				esc_url(add_query_arg('status', $key, $base)),
				$current === $key ? ' class="current"' : '',
				esc_html($label),
				$counts[$key] ?? 0
			);
		}
		return $views;
	}

	protected function extra_tablenav($which) {
		if ('top' !== $which) {
			return;
		}
		$from = sanitize_text_field($_GET['date_from'] ?? '');
		$to   = sanitize_text_field($_GET['date_to'] ?? '');
		?>
		<div class="alignleft actions">
			<label class="screen-reader-text" for="xdlb-from"><?php esc_html_e('From date', 'xdl-booking'); ?></label>
			<input type="date" id="xdlb-from" name="date_from" value="<?php echo esc_attr($from); ?>">
			<label class="screen-reader-text" for="xdlb-to"><?php esc_html_e('To date', 'xdl-booking'); ?></label>
			<input type="date" id="xdlb-to" name="date_to" value="<?php echo esc_attr($to); ?>">
			<?php submit_button(__('Filter', 'xdl-booking'), '', 'filter_action', false); ?>
		</div>
		<?php
	}

	public function prepare_items() {
		$per_page = $this->get_items_per_page('xdl_booking_per_page', 20);
		$status   = sanitize_key($_GET['status'] ?? '');
		$from     = sanitize_text_field($_GET['date_from'] ?? '');
		$to       = sanitize_text_field($_GET['date_to'] ?? '');

		$result = XDL_Booking_DB::query(array(
			'status'    => array_key_exists($status, XDL_Booking::statuses()) ? $status : '',
			'search'    => sanitize_text_field(wp_unslash($_GET['s'] ?? '')),
			'date_from' => XDL_Booking_Availability::is_valid_ymd($from) ? $from : '',
			'date_to'   => XDL_Booking_Availability::is_valid_ymd($to) ? $to : '',
			'orderby'   => sanitize_key($_GET['orderby'] ?? 'booking_date'),
			'order'     => sanitize_key($_GET['order'] ?? 'desc'),
			'per_page'  => $per_page,
			'page'      => $this->get_pagenum(),
		));

		$this->items = $result['rows'];
		$this->_column_headers = array($this->get_columns(), array(), $this->get_sortable_columns(), 'customer');
		$this->set_pagination_args(array(
			'total_items' => $result['total'],
			'per_page'    => $per_page,
		));
	}

	public function no_items() {
		esc_html_e('No bookings found.', 'xdl-booking');
	}

	protected function column_cb($item) {
		return sprintf('<input type="checkbox" name="ids[]" value="%d" />', (int) $item->id);
	}

	protected function column_customer($item) {
		$view = XDL_Booking_Admin::view_url($item->id);
		$out  = sprintf('<strong><a class="row-title" href="%s">%s</a></strong>', esc_url($view), esc_html($item->customer_name));
		$out .= '<br><a href="mailto:' . esc_attr($item->email) . '">' . esc_html($item->email) . '</a>';
		if ($item->phone) {
			$out .= '<br><a href="tel:' . esc_attr($item->phone) . '">' . esc_html($item->phone) . '</a>';
		}

		$actions = array('view' => sprintf('<a href="%s">%s</a>', esc_url($view), esc_html__('View', 'xdl-booking')));
		if (XDL_Booking::STATUS_CONFIRMED !== $item->status) {
			$actions['confirm'] = sprintf('<a href="%s">%s</a>', esc_url(XDL_Booking_Admin::status_url($item->id, XDL_Booking::STATUS_CONFIRMED)), esc_html__('Confirm', 'xdl-booking'));
		}
		if (XDL_Booking::STATUS_CANCELLED !== $item->status) {
			$actions['cancel'] = sprintf('<a href="%s">%s</a>', esc_url(XDL_Booking_Admin::status_url($item->id, XDL_Booking::STATUS_CANCELLED)), esc_html__('Cancel', 'xdl-booking'));
		}
		$actions['delete'] = sprintf(
			'<a href="%s" class="submitdelete" onclick="return confirm(\'%s\');">%s</a>',
			esc_url(XDL_Booking_Admin::delete_url($item->id)),
			esc_js(__('Delete this booking permanently?', 'xdl-booking')),
			esc_html__('Delete', 'xdl-booking')
		);

		return $out . $this->row_actions($actions);
	}

	protected function column_booking_date($item) {
		return '<strong>' . esc_html(XDL_Booking::format_date($item->booking_date)) . '</strong><br>'
			. esc_html(XDL_Booking::format_time($item->start_time) . ' – ' . XDL_Booking::format_time($item->end_time));
	}

	protected function column_services($item) {
		$out = esc_html($item->services);
		if ($item->note) {
			$out .= '<br><span class="description">' . esc_html(wp_trim_words($item->note, 12)) . '</span>';
		}
		return $out;
	}

	protected function column_wedding_date($item) {
		return esc_html(XDL_Booking::format_date($item->wedding_date)) ?: '—';
	}

	protected function column_status($item) {
		return XDL_Booking_Admin::status_badge($item->status);
	}

	protected function column_created_at($item) {
		return esc_html(XDL_Booking::format_datetime($item->created_at));
	}
}
