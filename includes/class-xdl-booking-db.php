<?php

if (!defined('ABSPATH')) {
	exit;
}

final class XDL_Booking_DB {

	const TABLE = 'xdl_bookings';

	public static function table() {
		global $wpdb;
		return $wpdb->prefix . self::TABLE;
	}

	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table   = self::table();
		$charset = $wpdb->get_charset_collate();

		dbDelta("CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			booking_date DATE NOT NULL,
			start_time TIME NOT NULL,
			end_time TIME NOT NULL,
			customer_name VARCHAR(190) NOT NULL,
			email VARCHAR(190) NOT NULL,
			phone VARCHAR(40) NOT NULL DEFAULT '',
			guest_emails TEXT NULL,
			wedding_date DATE NULL,
			services TEXT NULL,
			note TEXT NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'pending',
			ip VARCHAR(45) NOT NULL DEFAULT '',
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY date_status (booking_date, status),
			KEY status (status),
			KEY email (email)
		) {$charset};");
	}

	public static function insert(array $data) {
		global $wpdb;
		$now = XDL_Booking::now_mysql();
		$ok  = $wpdb->insert(self::table(), array_merge($data, array(
			'created_at' => $now,
			'updated_at' => $now,
		)));
		return $ok ? (int) $wpdb->insert_id : 0;
	}

	public static function get($id) {
		global $wpdb;
		$table = self::table();
		return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", absint($id)));
	}

	public static function update_status($id, $status) {
		global $wpdb;
		if (!array_key_exists($status, XDL_Booking::statuses())) {
			return false;
		}
		$row = self::get($id);
		if (!$row) {
			return false;
		}
		$ok = $wpdb->update(
			self::table(),
			array('status' => $status, 'updated_at' => XDL_Booking::now_mysql()),
			array('id' => absint($id))
		);
		if ($ok && $row->status !== $status) {
			do_action('xdl_booking_status_changed', (int) $id, $status, $row->status);
		}
		return (bool) $ok;
	}

	public static function delete($id) {
		global $wpdb;
		return (bool) $wpdb->delete(self::table(), array('id' => absint($id)));
	}

	/** Bookings that occupy slots, between two dates inclusive. */
	public static function blocking_between($from, $to) {
		global $wpdb;
		$table    = self::table();
		$statuses = XDL_Booking::blocking_statuses();
		$in       = implode(',', array_fill(0, count($statuses), '%s'));
		return $wpdb->get_results($wpdb->prepare(
			"SELECT booking_date, start_time, end_time FROM {$table}
			WHERE booking_date BETWEEN %s AND %s AND status IN ({$in})",
			array_merge(array($from, $to), $statuses)
		));
	}

	/** All bookings (any status) between two dates, for the admin calendar. */
	public static function between($from, $to) {
		global $wpdb;
		$table = self::table();
		return $wpdb->get_results($wpdb->prepare(
			"SELECT * FROM {$table} WHERE booking_date BETWEEN %s AND %s ORDER BY booking_date ASC, start_time ASC",
			$from,
			$to
		));
	}

	/**
	 * @param array $args status, search, date_from, date_to, orderby, order, per_page, page
	 * @return array{rows: array, total: int}
	 */
	public static function query(array $args) {
		global $wpdb;
		$table  = self::table();
		$where  = array('1=1');
		$params = array();

		if (!empty($args['status'])) {
			$where[]  = 'status = %s';
			$params[] = $args['status'];
		}
		if (!empty($args['search'])) {
			$like     = '%' . $wpdb->esc_like($args['search']) . '%';
			$where[]  = '(customer_name LIKE %s OR email LIKE %s OR phone LIKE %s OR guest_emails LIKE %s)';
			$params   = array_merge($params, array($like, $like, $like, $like));
		}
		if (!empty($args['date_from'])) {
			$where[]  = 'booking_date >= %s';
			$params[] = $args['date_from'];
		}
		if (!empty($args['date_to'])) {
			$where[]  = 'booking_date <= %s';
			$params[] = $args['date_to'];
		}

		$sortable = array('booking_date', 'created_at', 'customer_name', 'wedding_date', 'status');
		$orderby  = in_array($args['orderby'] ?? '', $sortable, true) ? $args['orderby'] : 'booking_date';
		$order    = 'ASC' === strtoupper($args['order'] ?? '') ? 'ASC' : 'DESC';
		$per_page = max(1, (int) ($args['per_page'] ?? 20));
		$offset   = max(0, ((int) ($args['page'] ?? 1) - 1) * $per_page);
		$where_sql = implode(' AND ', $where);
		$tiebreak  = 'booking_date' === $orderby ? ", start_time {$order}" : '';

		$sql_rows  = "SELECT * FROM {$table} WHERE {$where_sql} ORDER BY {$orderby} {$order}{$tiebreak}, id DESC LIMIT %d OFFSET %d";
		$sql_count = "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}";

		$rows  = $wpdb->get_results($wpdb->prepare($sql_rows, array_merge($params, array($per_page, $offset))));
		$total = $params ? (int) $wpdb->get_var($wpdb->prepare($sql_count, $params)) : (int) $wpdb->get_var($sql_count);

		return array('rows' => $rows, 'total' => $total);
	}

	public static function count_by_status() {
		global $wpdb;
		$table = self::table();
		$out   = array();
		foreach ($wpdb->get_results("SELECT status, COUNT(*) AS c FROM {$table} GROUP BY status") as $r) {
			$out[$r->status] = (int) $r->c;
		}
		return $out;
	}
}
