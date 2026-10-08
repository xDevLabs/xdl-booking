<?php

if (!defined('ABSPATH')) {
	exit;
}

class XDL_Booking_Element extends \Bricks\Element {

	public $category = 'general';
	public $name     = 'xdl-booking-form';
	public $icon     = 'ti-calendar';

	public function get_label() {
		return esc_html__('Booking Form (XDL Booking)', 'xdl-booking');
	}

	public function set_controls() {
		$this->controls['title'] = array(
			'tab'   => 'content',
			'label' => esc_html__('Title', 'xdl-booking'),
			'type'  => 'text',
		);
	}

	public function render() {
		echo "<div {$this->render_attributes('_root')}>" . XDL_Booking_Frontend::render(array('title' => $this->settings['title'] ?? '')) . '</div>';
	}
}
