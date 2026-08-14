<?php
if (!defined('ABSPATH')) {
	exit;
	// Exit if accessed directly
}
class Wxp_Partial_Shipment_Settings
{

	public static function init()
	{
		add_filter('woocommerce_settings_tabs_array', __CLASS__ . '::add_settings_tab', 50);
		add_action('woocommerce_settings_tabs_wxp_partial_shipping_settings', __CLASS__ . '::settings_tab');
		add_action('woocommerce_update_options_wxp_partial_shipping_settings', __CLASS__ . '::update_settings');
	}

	public static function add_settings_tab($settings_tabs)
	{
		$settings_tabs['wxp_partial_shipping_settings'] = __('Partial Shipment', 'wc-partial-shipment');
		return $settings_tabs;
	}

	public static function settings_tab()
	{
		woocommerce_admin_fields(self::get_settings());

		$base = html_entity_decode(
			wp_nonce_url(admin_url('admin-post.php?action=wxp_backfill_shipments'), 'wxp_backfill_shipments', 'wxp_backfill_nonce'),
			ENT_QUOTES
		);
		?>
		<h2><?php esc_html_e('Backfill existing orders', 'wc-partial-shipment'); ?></h2>
		<p><?php esc_html_e('Generate shipment records for past orders whose status matches the selection above. This is useful for orders placed before this plugin was active. Refunded quantities are excluded, and orders that already have shipment records are skipped.', 'wc-partial-shipment'); ?></p>
		<p>
			<a class="button button-secondary" id="wxp_backfill_link" href="<?php echo esc_url($base); ?>"><?php esc_html_e('Backfill orders now', 'wc-partial-shipment'); ?></a>
		</p>
		<script type="text/javascript">
			jQuery(function ($) {
				var base = <?php echo wp_json_encode($base); ?>;
				var $select = $('#wxp_backfill_status');
				function sync() {
					var vals = $select.val() || [];
					$('#wxp_backfill_link').attr('href', base + '&wxp_backfill_status=' + encodeURIComponent(vals.join(',')));
				}
				$select.on('change', sync);
				// Re-sync once WooCommerce has initialised selectWoo on the field.
				$(document.body).on('wc-enhanced-select-init wc-enhanced-select-enhanced', sync);
				sync();
			});
		</script>
		<?php
	}

	public static function update_settings()
	{
		woocommerce_update_options(self::get_settings());
	}

	public static function get_settings()
	{

		$settings = array(
			'section_title' => array(
				'name'     => __('WooCommerce Partial Shipment', 'wc-partial-shipment'),
				'type'     => 'title',
				'id'       => 'wxp_partial_shipping_settings_section_title'
			),
			'partially_shipped' => array(
				'title'   => __('Partially Shipped status', 'wc-partial-shipment'),
				'desc'    => __('Register a custom "Partially Shipped" order status for orders where only some items have been shipped.', 'wc-partial-shipment'),
				'id'      => 'partially_shipped_status',
				'type'    => 'checkbox',
				'default' => 'yes',
			),
			'auto_complete' => array(
				'title'   => __('Auto-complete when fully shipped', 'wc-partial-shipment'),
				'desc'    => __('Automatically move the order to Completed once every item has been marked as shipped.', 'wc-partial-shipment'),
				'id'      => 'partially_auto_complete',
				'type'    => 'checkbox',
				'default' => 'yes',
			),
			'partially_hide_status' => array(
				'title'   => __('Hide status until partial', 'wc-partial-shipment'),
				'desc'    => __('Hide the shipment status on the order page until at least one item has been partially shipped.', 'wc-partial-shipment'),
				'id'      => 'partially_hide_status',
				'type'    => 'checkbox',
				'default' => 'yes',
			),
			'partially_enable_status_popup' => array(
				'title'   => __('Show status in orders list popup', 'wc-partial-shipment'),
				'desc'    => __('Display the shipment status inside the order details popup on the WooCommerce orders list.', 'wc-partial-shipment'),
				'id'      => 'partially_enable_status_popup',
				'type'    => 'checkbox',
				'default' => 'yes',
			),
			'backfill_statuses' => array(
				'title'    => __('Backfill statuses', 'wc-partial-shipment'),
				'desc'     => __('Orders with the selected statuses that have no shipment record yet will be backfilled (refund-aware). Already-processed orders are skipped.', 'wc-partial-shipment'),
				'id'       => 'wxp_backfill_status',
				'type'     => 'multiselect',
				'options'  => wc_get_order_statuses(),
				'default'  => array('wc-completed'),
				'class'    => 'wc-enhanced-select',
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
