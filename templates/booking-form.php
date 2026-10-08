<?php
/**
 * Booking form markup. Override by copying to {theme}/xdl-booking/booking-form.php.
 *
 * @var string[] $services
 * @var string   $accent
 * @var string   $title
 */

if (!defined('ABSPATH')) {
	exit;
}

$uid = wp_unique_id('xdlb-');
?>
<div class="xdlb" id="<?php echo esc_attr($uid); ?>" style="--xdlb-accent: <?php echo esc_attr($accent); ?>;">
	<?php if ($title) : ?>
		<h2 class="xdlb-title"><?php echo esc_html($title); ?></h2>
	<?php endif; ?>

	<div class="xdlb-schedule" data-xdlb-schedule>
		<div class="xdlb-panel xdlb-cal">
			<p class="xdlb-step-label"><span>1</span><?php esc_html_e('Choose a date', 'xdl-booking'); ?></p>
			<div class="xdlb-cal-head">
				<button type="button" class="xdlb-cal-nav" data-xdlb-prev aria-label="<?php esc_attr_e('Previous month', 'xdl-booking'); ?>">&#8249;</button>
				<strong class="xdlb-cal-month" data-xdlb-month aria-live="polite"></strong>
				<button type="button" class="xdlb-cal-nav" data-xdlb-next aria-label="<?php esc_attr_e('Next month', 'xdl-booking'); ?>">&#8250;</button>
			</div>
			<div class="xdlb-cal-grid" data-xdlb-grid role="grid"></div>
		</div>

		<div class="xdlb-panel xdlb-slots">
			<p class="xdlb-step-label"><span>2</span><?php esc_html_e('Choose a time', 'xdl-booking'); ?></p>
			<p class="xdlb-slots-date" data-xdlb-slots-date><?php esc_html_e('Select a date to see available times.', 'xdl-booking'); ?></p>
			<div class="xdlb-slot-list" data-xdlb-slot-list></div>
		</div>
	</div>

	<form class="xdlb-panel xdlb-form" data-xdlb-form novalidate hidden>
		<p class="xdlb-step-label"><span>3</span><?php esc_html_e('Your details', 'xdl-booking'); ?></p>
		<p class="xdlb-picked"><span data-xdlb-picked></span></p>

		<input type="hidden" name="date" value="">
		<input type="hidden" name="time" value="">
		<input type="hidden" name="ts" value="<?php echo esc_attr(time()); ?>">
		<div class="xdlb-hp" aria-hidden="true">
			<label><?php esc_html_e('Website', 'xdl-booking'); ?><input type="text" name="website" tabindex="-1" autocomplete="off"></label>
		</div>

		<div class="xdlb-grid-2">
			<div class="xdlb-field">
				<label for="<?php echo esc_attr($uid); ?>-name"><?php esc_html_e('Full name', 'xdl-booking'); ?> <abbr title="<?php esc_attr_e('required', 'xdl-booking'); ?>">*</abbr></label>
				<input id="<?php echo esc_attr($uid); ?>-name" type="text" name="name" required maxlength="190" autocomplete="name">
				<small class="xdlb-error" data-xdlb-error="name"></small>
			</div>

			<div class="xdlb-field">
				<label for="<?php echo esc_attr($uid); ?>-email"><?php esc_html_e('Email', 'xdl-booking'); ?> <abbr title="<?php esc_attr_e('required', 'xdl-booking'); ?>">*</abbr></label>
				<input id="<?php echo esc_attr($uid); ?>-email" type="email" name="email" required maxlength="190" autocomplete="email">
				<small class="xdlb-error" data-xdlb-error="email"></small>
			</div>

			<div class="xdlb-field">
				<label for="<?php echo esc_attr($uid); ?>-phone"><?php esc_html_e('Phone', 'xdl-booking'); ?> <abbr title="<?php esc_attr_e('required', 'xdl-booking'); ?>">*</abbr></label>
				<input id="<?php echo esc_attr($uid); ?>-phone" type="tel" name="phone" required autocomplete="tel" data-xdlb-phone>
				<small class="xdlb-error" data-xdlb-error="phone"></small>
			</div>

			<div class="xdlb-field">
				<label for="<?php echo esc_attr($uid); ?>-wedding"><?php esc_html_e('When is your wedding date?', 'xdl-booking'); ?></label>
				<input id="<?php echo esc_attr($uid); ?>-wedding" type="date" name="wedding_date" min="<?php echo esc_attr(XDL_Booking_Availability::today()); ?>">
				<small class="xdlb-error" data-xdlb-error="wedding_date"></small>
			</div>
		</div>

		<div class="xdlb-field xdlb-guests">
			<button type="button" class="xdlb-link" data-xdlb-guests-toggle aria-expanded="false" aria-controls="<?php echo esc_attr($uid); ?>-guests">
				+ <?php esc_html_e('Add guest emails', 'xdl-booking'); ?>
			</button>
			<div data-xdlb-guests hidden>
				<label for="<?php echo esc_attr($uid); ?>-guests"><?php esc_html_e('Guest emails', 'xdl-booking'); ?></label>
				<textarea id="<?php echo esc_attr($uid); ?>-guests" name="guest_emails" rows="2" placeholder="<?php esc_attr_e('mom@example.com, bestie@example.com', 'xdl-booking'); ?>"></textarea>
				<small class="xdlb-hint"><?php esc_html_e('Separate emails with commas. Guests will receive a copy of the confirmation.', 'xdl-booking'); ?></small>
				<small class="xdlb-error" data-xdlb-error="guest_emails"></small>
			</div>
		</div>

		<?php if ($services) : ?>
			<fieldset class="xdlb-field xdlb-services">
				<legend><?php esc_html_e('Services', 'xdl-booking'); ?> <abbr title="<?php esc_attr_e('required', 'xdl-booking'); ?>">*</abbr></legend>
				<?php foreach ($services as $i => $service) : ?>
					<label class="xdlb-check">
						<input type="checkbox" name="services[]" value="<?php echo esc_attr($service); ?>" <?php checked(1 === count($services)); ?>>
						<span><?php echo esc_html($service); ?></span>
					</label>
				<?php endforeach; ?>
				<small class="xdlb-error" data-xdlb-error="services"></small>
			</fieldset>
		<?php endif; ?>

		<div class="xdlb-field">
			<label for="<?php echo esc_attr($uid); ?>-note"><?php esc_html_e('Note', 'xdl-booking'); ?></label>
			<textarea id="<?php echo esc_attr($uid); ?>-note" name="note" rows="4" maxlength="2000" placeholder="<?php esc_attr_e('Tell us about your style, preferred dresses or anything we should know.', 'xdl-booking'); ?>"></textarea>
			<small class="xdlb-error" data-xdlb-error="note"></small>
		</div>

		<div class="xdlb-alert" data-xdlb-alert role="alert" hidden></div>

		<button type="submit" class="xdlb-submit" data-xdlb-submit><?php esc_html_e('Book appointment', 'xdl-booking'); ?></button>
	</form>

	<div class="xdlb-panel xdlb-success" data-xdlb-success role="status" hidden>
		<div class="xdlb-success-icon" aria-hidden="true">&#10003;</div>
		<p class="xdlb-success-when" data-xdlb-success-when></p>
		<p data-xdlb-success-msg></p>
	</div>
</div>
