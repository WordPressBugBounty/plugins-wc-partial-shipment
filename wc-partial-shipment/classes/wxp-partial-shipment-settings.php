<?php
if (!defined('ABSPATH')) {
	exit;
	// Exit if accessed directly
}
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound -- Established public class name of this plugin.
class Wxp_Partial_Shipment_Settings
{

	public static function init()
	{
		add_filter('woocommerce_settings_tabs_array', __CLASS__ . '::add_settings_tab', 50);
		add_action('woocommerce_settings_tabs_wxp_partial_shipping_settings', __CLASS__ . '::settings_tab');
		add_action('woocommerce_update_options_wxp_partial_shipping_settings', __CLASS__ . '::update_settings');
		// Display-only rows (no option id, so WooCommerce never saves them).
		add_action('woocommerce_admin_field_wxp_badge_preview', __CLASS__ . '::badge_preview_field');
		add_action('woocommerce_admin_field_wxp_email_status', __CLASS__ . '::email_status_field');
		add_action('woocommerce_admin_field_wxp_backfill_action', __CLASS__ . '::backfill_action_field');
	}

	public static function add_settings_tab($settings_tabs)
	{
		$settings_tabs['wxp_partial_shipping_settings'] = __('Partial Shipment', 'wc-partial-shipment');
		return $settings_tabs;
	}

	/**
	 * Settings page: a header card, then one card per settings section.
	 */
	public static function settings_tab()
	{
		echo '<div class="wxp-settings">';
		self::render_header();
		foreach (self::get_sections(self::get_settings()) as $fields) {
			echo '<div class="wxp-card">';
			woocommerce_admin_fields($fields);
			echo '</div>';
		}
		echo '</div>';
	}

	public static function update_settings()
	{
		woocommerce_update_options(self::get_settings());
	}

	/**
	 * Split the flat settings list into title...sectionend groups (one card each).
	 * Fields added through the wxp_partial_shipping_settings filter stay in the
	 * section they were inserted into.
	 *
	 * @param array $settings Settings fields.
	 * @return array[]
	 */
	public static function get_sections($settings)
	{
		$sections = array();
		$current  = array();
		foreach ((array) $settings as $key => $field) {
			$type = isset($field['type']) ? $field['type'] : '';
			if ('title' === $type && $current) {
				$sections[] = $current;
				$current    = array();
			}
			$current[$key] = $field;
			if ('sectionend' === $type) {
				$sections[] = $current;
				$current    = array();
			}
		}
		if ($current) {
			$sections[] = $current;
		}
		return $sections;
	}

	/**
	 * Header card: plugin name, short summary, links and the number of partially shipped orders.
	 */
	private static function render_header()
	{
		$stat = '';
		if (wc_is_order_status('wc-partial-shipped')) {
			$count = (int) wc_orders_count('partial-shipped');
			$url   = \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled()
				? admin_url('admin.php?page=wc-orders&status=wc-partial-shipped')
				: admin_url('edit.php?post_status=wc-partial-shipped&post_type=shop_order');
			$stat = '<a class="wxp-stat" href="' . esc_url($url) . '"><span class="wxp-stat__num">' . esc_html(number_format_i18n($count)) . '</span><span class="wxp-stat__label">' . esc_html(_n('Partially shipped order', 'Partially shipped orders', $count, 'wc-partial-shipment')) . '</span><span class="wxp-stat__link">' . esc_html__('View orders', 'wc-partial-shipment') . ' &rarr;</span></a>';
		}
		$new_tab = '<span class="screen-reader-text"> ' . esc_html__('(opens in a new tab)', 'wc-partial-shipment') . '</span><span class="dashicons dashicons-external" aria-hidden="true"></span>';
		?>
		<div class="wxp-hero">
			<span class="wxp-hero__icon" aria-hidden="true"><span class="dashicons dashicons-car"></span></span>
			<div class="wxp-hero__body">
				<h2 class="wxp-hero__title">
					<?php esc_html_e('Partial Shipment for WooCommerce', 'wc-partial-shipment'); ?>
					<span class="wxp-pill"><?php echo esc_html('v' . WXP_PARTIAL_SHIP_VER); ?></span>
				</h2>
				<p class="wxp-hero__text"><?php esc_html_e('Ship orders in parts: record how much of each item has shipped, follow it with status badges and tell customers which items are on their way.', 'wc-partial-shipment'); ?></p>
				<p class="wxp-hero__links">
					<a href="https://wpexpertshub.com/plugins/wc-partial-shipment/" target="_blank" rel="noopener noreferrer"><?php esc_html_e('Documentation', 'wc-partial-shipment'); ?><?php echo $new_tab; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built above from escaped strings. ?></a>
					<a href="https://wordpress.org/support/plugin/wc-partial-shipment/" target="_blank" rel="noopener noreferrer"><?php esc_html_e('Support forum', 'wc-partial-shipment'); ?><?php echo $new_tab; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built above from escaped strings. ?></a>
				</p>
			</div>
			<?php echo $stat; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built above with esc_url()/esc_html(). ?>
		</div>
		<?php
	}

	/**
	 * Preview of the four status badges, exactly as they render on orders.
	 *
	 * @param array $value Field definition.
	 */
	public static function badge_preview_field($value)
	{
		$plugin = wxp_partial_shipment_init();
		?>
		<tr>
			<th scope="row" class="titledesc"><?php echo esc_html($value['title']); ?></th>
			<td class="forminp">
				<div class="wxp-badge-preview">
					<?php
					// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- get_badge_html() escapes its output.
					echo $plugin->get_badge_html('shipped', 2, 2);
					echo $plugin->get_badge_html('partial', 1, 3);
					echo $plugin->get_badge_html('not', 3, 3);
					echo $plugin->get_badge_html('refunded', 1, 0);
					// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
					?>
				</div>
				<p class="description"><?php esc_html_e('Shown next to each item on the order screen, in the orders list preview and on the customer\'s View Order page.', 'wc-partial-shipment'); ?></p>
			</td>
		</tr>
		<?php
	}

	/**
	 * State of the "Partial shipment order" email with a link to its settings.
	 *
	 * @param array $value Field definition.
	 */
	public static function email_status_field($value)
	{
		$emails  = WC()->mailer()->get_emails();
		$email   = isset($emails['WC_Email_Customer_Partial_shipment']) ? $emails['WC_Email_Customer_Partial_shipment'] : null;
		$enabled = $email && $email->is_enabled();
		$types   = $email ? $email->get_email_type_options() : array();
		$type    = $email ? $email->get_email_type() : '';
		?>
		<tr>
			<th scope="row" class="titledesc"><?php echo esc_html($value['title']); ?></th>
			<td class="forminp">
				<div class="wxp-inline">
					<span class="wxp-state <?php echo $enabled ? 'is-on' : 'is-off'; ?>"><?php echo $enabled ? esc_html__('Enabled', 'wc-partial-shipment') : esc_html__('Disabled', 'wc-partial-shipment'); ?></span>
					<?php if ($type && isset($types[$type])) : ?>
						<span class="wxp-meta"><?php echo esc_html($types[$type]); ?></span>
					<?php endif; ?>
					<a class="button" href="<?php echo esc_url(admin_url('admin.php?page=wc-settings&tab=email&section=wc_email_customer_partial_shipment')); ?>"><?php esc_html_e('Manage email', 'wc-partial-shipment'); ?></a>
				</div>
				<p class="description"><?php esc_html_e('Sent when you choose "Partial shipment notification" under Order actions on an order.', 'wc-partial-shipment'); ?></p>
			</td>
		</tr>
		<?php
	}

	/**
	 * Backfill button with the state of the current or last run.
	 *
	 * @param array $value Field definition.
	 */
	public static function backfill_action_field($value)
	{
		$plugin = wxp_partial_shipment_init();
		$job    = $plugin->wxp_backfill_get_job();
		$last   = get_option('wxp_backfill_last');
		$base   = html_entity_decode(
			wp_nonce_url(admin_url('admin-post.php?action=wxp_backfill_shipments'), 'wxp_backfill_shipments', 'wxp_backfill_nonce'),
			ENT_QUOTES
		);
		$here   = admin_url('admin.php?page=wc-settings&tab=wxp_partial_shipping_settings');
		?>
		<tr>
			<th scope="row" class="titledesc"><?php echo esc_html($value['title']); ?></th>
			<td class="forminp">
				<?php if ($job) : ?>
					<span class="button button-secondary disabled" aria-disabled="true"><?php esc_html_e('Backfill running…', 'wc-partial-shipment'); ?></span>
					<p class="wxp-backfill-state is-running">
						<span class="spinner is-active" aria-hidden="true"></span>
						<span>
							<?php
							printf(
								/* translators: 1: order status label(s), 2: orders backfilled so far, 3: orders skipped so far. */
								esc_html__('Running in the background for "%1$s": %2$d backfilled and %3$d skipped so far.', 'wc-partial-shipment'),
								esc_html($job['status_label']),
								(int) $job['processed'],
								(int) $job['skipped']
							);
							?>
							<a href="<?php echo esc_url($here); ?>"><?php esc_html_e('Refresh', 'wc-partial-shipment'); ?></a>
						</span>
					</p>
				<?php else : ?>
					<a class="button button-secondary" id="wxp_backfill_link" data-base="<?php echo esc_attr($base); ?>" href="<?php echo esc_url($base); ?>"><?php esc_html_e('Backfill orders now', 'wc-partial-shipment'); ?></a>
					<?php if (is_array($last) && isset($last['finished'])) : ?>
						<p class="wxp-backfill-state is-done">
							<span class="dashicons dashicons-yes-alt" aria-hidden="true"></span>
							<span>
								<?php
								printf(
									/* translators: 1: date and time, 2: order status label(s), 3: orders backfilled, 4: orders skipped. */
									esc_html__('Last run %1$s for "%2$s": %3$d backfilled, %4$d skipped.', 'wc-partial-shipment'),
									esc_html(wp_date(get_option('date_format') . ' ' . get_option('time_format'), (int) $last['finished'])),
									esc_html($last['status_label']),
									(int) $last['processed'],
									(int) $last['skipped']
								);
								?>
							</span>
						</p>
					<?php else : ?>
						<p class="wxp-backfill-state"><span><?php esc_html_e('Not run yet.', 'wc-partial-shipment'); ?></span></p>
					<?php endif; ?>
				<?php endif; ?>
				<p class="description"><?php esc_html_e('Orders are processed in batches of 50. On larger stores the remaining batches continue in the background and the progress is shown here.', 'wc-partial-shipment'); ?></p>
			</td>
		</tr>
		<?php
	}

	public static function get_settings()
	{
		$toggle = array('role' => 'switch');

		$settings = array(
			'section_title' => array(
				'name'     => __('Order status', 'wc-partial-shipment'),
				'type'     => 'title',
				'desc'     => __('How the order status follows the shipment.', 'wc-partial-shipment'),
				'id'       => 'wxp_partial_shipping_settings_section_title'
			),
			'partially_shipped' => array(
				'title'   => __('Partially Shipped status', 'wc-partial-shipment'),
				'desc'    => __('Add a "Partially Shipped" order status, used while only some items have shipped.', 'wc-partial-shipment'),
				'id'      => 'partially_shipped_status',
				'type'    => 'checkbox',
				'default' => 'yes',
				'class'   => 'wxp-toggle',
				'custom_attributes' => $toggle,
			),
			'auto_complete' => array(
				'title'   => __('Auto-complete when fully shipped', 'wc-partial-shipment'),
				'desc'    => __('Move the order to Completed as soon as every item has shipped.', 'wc-partial-shipment'),
				'id'      => 'partially_auto_complete',
				'type'    => 'checkbox',
				'default' => 'yes',
				'class'   => 'wxp-toggle',
				'custom_attributes' => $toggle,
			),
			'status_end' => array(
				'type' => 'sectionend',
				'id'   => 'wxp_partial_shipping_status_section_end'
			),
			'badges_title' => array(
				'name' => __('Shipment badges', 'wc-partial-shipment'),
				'type' => 'title',
				'desc' => __('Each order item gets a badge that shows how much of it has shipped.', 'wc-partial-shipment'),
				'id'   => 'wxp_partial_shipping_badges_section'
			),
			'badge_preview' => array(
				'title' => __('Preview', 'wc-partial-shipment'),
				'type'  => 'wxp_badge_preview',
			),
			'partially_hide_status' => array(
				'title'   => __('Hide until first shipment', 'wc-partial-shipment'),
				'desc'    => __('On the customer\'s View Order page, show the badges only once a shipment has been saved for the order.', 'wc-partial-shipment'),
				'id'      => 'partially_hide_status',
				'type'    => 'checkbox',
				'default' => 'yes',
				'class'   => 'wxp-toggle',
				'custom_attributes' => $toggle,
			),
			'partially_enable_status_popup' => array(
				'title'   => __('Orders list preview', 'wc-partial-shipment'),
				'desc'    => __('Show the badges in the order preview popup on the Orders screen.', 'wc-partial-shipment'),
				'id'      => 'partially_enable_status_popup',
				'type'    => 'checkbox',
				'default' => 'yes',
				'class'   => 'wxp-toggle',
				'custom_attributes' => $toggle,
			),
			'badges_end' => array(
				'type' => 'sectionend',
				'id'   => 'wxp_partial_shipping_badges_section_end'
			),
			'email_title' => array(
				'name' => __('Notification email', 'wc-partial-shipment'),
				'type' => 'title',
				'desc' => __('Tell the customer which items have shipped and which are still to come.', 'wc-partial-shipment'),
				'id'   => 'wxp_partial_shipping_email_section'
			),
			'email_status' => array(
				'title' => __('Partial shipment email', 'wc-partial-shipment'),
				'type'  => 'wxp_email_status',
			),
			'email_end' => array(
				'type' => 'sectionend',
				'id'   => 'wxp_partial_shipping_email_section_end'
			),
			'backfill_title' => array(
				'name' => __('Backfill existing orders', 'wc-partial-shipment'),
				'type' => 'title',
				'desc' => __('Create shipment records for orders placed before this plugin was active. Refunded quantities are excluded, and orders that already have shipment records are skipped.', 'wc-partial-shipment'),
				'id'   => 'wxp_partial_shipping_backfill_section'
			),
			'backfill_statuses' => array(
				'title'    => __('Order statuses', 'wc-partial-shipment'),
				'desc'     => __('Orders with these statuses and no shipment record yet will have all their items marked as shipped.', 'wc-partial-shipment'),
				'id'       => 'wxp_backfill_status',
				'type'     => 'multiselect',
				'options'  => wc_get_order_statuses(),
				'default'  => array('wc-completed'),
				'class'    => 'wc-enhanced-select',
			),
			'backfill_action' => array(
				'title' => __('Run', 'wc-partial-shipment'),
				'type'  => 'wxp_backfill_action',
			),
			'section_end' => array(
				'type' => 'sectionend',
				'id' => 'wxp_partial_shipping_settings_section_end'
			)
		);
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Custom plugin hook.
		return apply_filters('wxp_partial_shipping_settings', $settings);
	}
}
