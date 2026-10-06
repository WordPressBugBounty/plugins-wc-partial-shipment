<?php

/**
 * Plugin Name: Partial Shipment for WooCommerce
 * Plugin URI: https://wpexpertshub.com/plugins/wc-partial-shipment/
 * Description: Ship WooCommerce orders in parts: record shipped quantities per item, update the order status automatically and show customers what has shipped.
 * Author: WpExperts Hub
 * Version: 3.8
 * Author URI: https://wpexpertshub.com/
 * Text Domain: wc-partial-shipment
 * Domain Path: /languages
 * License: GPLv3
 * License URI: https://www.gnu.org/licenses/gpl-3.0.html
 * Requires Plugins: woocommerce
 * Requires at least: 6.5
 * Tested up to: 7.1
 * Requires PHP: 7.4
 * WC requires at least: 8.0
 * WC tested up to: 11.1
 **/


defined('ABSPATH') || exit;

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound -- Established public class name of this plugin.
class WXP_Partial_Shipment
{

	const BACKFILL_HOOK  = 'wxp_partial_shipment_backfill_batch';
	const BACKFILL_GROUP = 'wc-partial-shipment';
	const BACKFILL_JOB   = 'wxp_backfill_job';
	const BACKFILL_BATCH = 50;

	protected static $_instance = null;
	protected $wc_partial_labels = array();
	protected $wc_partial_shipment_settings = array();
	/** Shipped quantities per order for the current request (an order page asks once per item row). */
	protected $shipment_data_cache = array();
	public static function instance()
	{

		if (is_null(self::$_instance)) {
			self::$_instance = new self();
		}
		return self::$_instance;
	}

	function __construct()
	{
		if (!defined('WXP_PARTIAL_SHIP_VER')) {
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- Established public constant of this plugin.
			define('WXP_PARTIAL_SHIP_VER', '3.8');
		}
		if (!defined('WXP_PARTIAL_SHIP_DIR')) {
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- Established public constant of this plugin.
			define('WXP_PARTIAL_SHIP_DIR', __DIR__);
		}
		add_action('before_woocommerce_init', array($this, 'hpos_compatibility'));
		add_action('plugins_loaded', array($this, 'maybe_upgrade_db'));
		add_action('init', array($this, 'init_autoload'));
		add_action('init', array($this, 'autoload_classes'));
		add_action('init', array($this, 'load_settings'));
		add_action('init', array($this, 'wxp_partial_complete_register_status'), 999);
		add_filter('plugin_action_links_' . plugin_basename(__FILE__), array($this, 'wxp_partial_action_links'), 10, 1);
		register_activation_hook(__FILE__, array($this, 'partial_shipment_active'));
		register_deactivation_hook(__FILE__, array($this, 'partial_shipment_deactivate'));

		add_action('woocommerce_admin_order_item_headers', array($this, 'wxp_order_item_headers'), 10, 1);
		add_action('woocommerce_admin_order_item_values', array($this, 'wxp_order_item_values'), 10, 3);
		add_action('admin_enqueue_scripts', array($this, 'wxp_admin_head'), 999);
		add_action('wp_enqueue_scripts', array($this, 'wxp_front'));
		add_action('woocommerce_order_item_add_action_buttons', array($this, 'wxp_order_shipment_button'), 10, 1);

		add_action('wp_ajax_wxp_order_shipment', array($this, 'wxp_order_shipment'));
		add_action('wp_ajax_wxp_order_item_shipment', array($this, 'wxp_order_item_shipment'));
		add_action('wp_ajax_wxp_order_set_shipped', array($this, 'wxp_order_set_shipped'));

		add_action('woocommerce_order_item_meta_end', array($this, 'wxp_order_item_icons'), 999, 4);
		add_filter('wc_order_statuses', array($this, 'add_partial_complete_status'));

		add_filter('woocommerce_admin_order_preview_line_item_columns', array($this, 'wxp_order_status_in_popup'), 10, 2);
		add_filter('woocommerce_admin_order_preview_line_item_column_wxp_status', array($this, 'wxp_order_status_in_popup_value'), 10, 4);
		add_action('woocommerce_order_actions', array($this, 'wxp_shipment_mail'), 10, 1);
		add_action('woocommerce_order_action_wxp_partial_shipment', array($this, 'trigger_wxp_shipment_mail'), 10, 1);
		add_filter('woocommerce_email_classes', array($this, 'wxp_shipment_email_class'), 10, 1);

		add_action('woocommerce_email_partially_shipped_order_details', array($this, 'order_details'), 10, 4);
		add_action('wxp_order_status', array($this, 'wxp_order_status_update'), 10, 1);
		add_action('woocommerce_order_status_completed', array($this, 'wxp_order_status_switch'), 10, 1);
		// A refund changes the quantity still owed: re-evaluate the automatic status.
		add_action('woocommerce_order_refunded', array($this, 'wxp_order_status_after_refund'), 20, 2);
		// A partially shipped order is a paid order: keep downloads, reviews and customer totals working.
		add_filter('woocommerce_order_is_paid_statuses', array($this, 'wxp_paid_statuses'));
		add_filter('woocommerce_order_is_download_permitted', array($this, 'wxp_download_permitted'), 10, 2);

		// Backfill tool: create shipment rows for past orders (status selectable).
		add_action('admin_post_wxp_backfill_shipments', array($this, 'wxp_backfill_orders'));
		add_action(self::BACKFILL_HOOK, array($this, 'wxp_backfill_batch'), 10, 1);
		add_action('admin_notices', array($this, 'wxp_backfill_admin_notice'));
	}

	function hpos_compatibility()
	{
		if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
			// Orders are only accessed through the WooCommerce CRUD API (HPOS safe) and
			// nothing is added to the cart/checkout (blocks unaffected).
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('cart_checkout_blocks', __FILE__, true);
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('product_block_editor', __FILE__, true);
		}
	}

	function partial_shipment_active()
	{
		include_once($this->plugin_path() . '/classes/wxp-partial-shipment-sql.php');
		$sql = new Wxp_Partial_Shipment_Sql();
		$sql->create();
		update_option('wxp_partial_shipment_db_version', WXP_PARTIAL_SHIP_VER);
	}

	/**
	 * Re-run the table creation/upgrade routine for sites that already had the
	 * plugin active when the schema changed (e.g. new indexes), since the
	 * activation hook only fires on a fresh activation.
	 */
	function maybe_upgrade_db()
	{
		if (get_option('wxp_partial_shipment_db_version') === WXP_PARTIAL_SHIP_VER) {
			return;
		}
		include_once($this->plugin_path() . '/classes/wxp-partial-shipment-sql.php');
		$sql = new Wxp_Partial_Shipment_Sql();
		$sql->create();
		update_option('wxp_partial_shipment_db_version', WXP_PARTIAL_SHIP_VER);
	}

	function wxp_partial_action_links($links)
	{
		$wxp_link = array(
			'<a href="' . admin_url('admin.php?page=wc-settings&tab=wxp_partial_shipping_settings') . '">' . esc_html__('Settings', 'wc-partial-shipment') . '</a>',
			'<a target="_blank" href="https://wpexpertshub.com/plugins/advance-partial-shipment-for-woocommerce/">' . esc_html__('Get Pro', 'wc-partial-shipment') . '</a>',
		);
		return array_merge($links, $wxp_link);
	}

	function plugin_path()
	{
		return untrailingslashit(plugin_dir_path(__FILE__));
	}

	function init_autoload()
	{
		spl_autoload_register(function ($class) {
			$class = strtolower($class);
			$class = str_replace('_', '-', $class);
			if (is_file(dirname(__FILE__) . '/classes/' . $class . '.php')) {
				include_once('classes/' . $class . '.php');
			}
		});
	}

	function autoload_classes()
	{
		$wcp = new Wxp_Partial_Shipment_Settings();
		$wcp->init();
	}

	function load_settings()
	{
		$this->wc_partial_labels = array(
			'shipped' => __('Shipped', 'wc-partial-shipment'),
			'not-shipped' => __('Not Shipped', 'wc-partial-shipment'),
			'partially-shipped' => __('Partially Shipped', 'wc-partial-shipment'),
			'refunded' => __('Refunded', 'wc-partial-shipment'),
		);
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Custom plugin hook.
		$this->wc_partial_labels = apply_filters('wxp_partial_shipment_labels', $this->wc_partial_labels);
		$this->wc_partial_shipment_settings = array(
			'partially_shipped_status' => get_option('partially_shipped_status') != '' ? get_option('partially_shipped_status') : 'yes',
			'partially_auto_complete' => get_option('partially_auto_complete') != '' ? get_option('partially_auto_complete') : 'yes',
			'partially_hide_status' => get_option('partially_hide_status') != '' ? get_option('partially_hide_status') : 'yes',
			'partially_enable_status_popup' => get_option('partially_enable_status_popup') != '' ? get_option('partially_enable_status_popup') : 'yes',
		);
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Custom plugin hook.
		$this->wc_partial_shipment_settings = apply_filters('wxp_partial_shipment_settings', $this->wc_partial_shipment_settings);
	}

	function wxp_front()
	{
		// The shipment status labels are only shown on the My Account order view.
		if (function_exists('is_account_page') && is_account_page()) {
			wp_enqueue_style('wxp_front_style', plugins_url('', __FILE__) . '/assets/css/front.css', array(), WXP_PARTIAL_SHIP_VER);
		}
	}

	function wxp_admin_head()
	{
		$screen = get_current_screen();
		if (! isset($screen->id)) {
			return;
		}
		if (in_array($screen->id, array('woocommerce_page_wc-orders', 'shop_order'))) {
			wp_enqueue_style('wxp_modal_style', plugins_url('', __FILE__) . '/assets/css/modal.css', array(), WXP_PARTIAL_SHIP_VER);
			wp_enqueue_style('wxp_style', plugins_url('', __FILE__) . '/assets/css/admin-style.css', array(), WXP_PARTIAL_SHIP_VER);
			wp_enqueue_script('wxp_modal_script', plugins_url('', __FILE__) . '/assets/js/modal.js', array(), WXP_PARTIAL_SHIP_VER, true);
			wp_register_script('wxp_partial_ship_script', plugins_url('', __FILE__) . '/assets/js/admin-script.js', array('jquery', 'wxp_modal_script'), WXP_PARTIAL_SHIP_VER, true);

			$js_array = array(
				'wxp_ajax' => admin_url('admin-ajax.php'),
				'wxp_error' => __('The shipment could not be loaded or saved. Please reload the page and try again.', 'wc-partial-shipment'),
				'wxp_order_nonce' => wp_create_nonce('order-item'),
				// Badge labels (filterable through wxp_partial_shipment_labels).
				'labels' => $this->wc_partial_labels,
				'i18n' => array(
					'title'         => __('Shipment', 'wc-partial-shipment'),
					/* translators: %s: order number. */
					'title_order'   => __('Order #%s shipment', 'wc-partial-shipment'),
					/* translators: 1: units shipped, 2: units to ship. */
					'summary'       => __('%1$s of %2$s units shipped', 'wc-partial-shipment'),
					'state_none'    => __('Not shipped', 'wc-partial-shipment'),
					'state_partial' => __('Partially shipped', 'wc-partial-shipment'),
					'state_full'    => __('Fully shipped', 'wc-partial-shipment'),
					'state_empty'   => __('Nothing to ship', 'wc-partial-shipment'),
					'unsaved'       => __('Unsaved changes', 'wc-partial-shipment'),
					/* translators: %s: product SKU. */
					'sku'           => __('SKU: %s', 'wc-partial-shipment'),
					/* translators: %s: ordered quantity. */
					'ordered'       => __('Qty %s', 'wc-partial-shipment'),
					/* translators: %s: refunded quantity. */
					'refunded_qty'  => __('%s refunded', 'wc-partial-shipment'),
					/* translators: %s: shipped quantity before the change. */
					'undo'          => __('Undo change (was %s)', 'wc-partial-shipment'),
					'virtual'       => __('No shipping needed', 'wc-partial-shipment'),
					'virtual_note'  => __('Virtual item', 'wc-partial-shipment'),
					'refunded_note' => __('Fully refunded', 'wc-partial-shipment'),
					/* translators: 1: product name, 2: quantity that can be shipped. */
					'qty_label'     => __('Shipped quantity for %1$s, out of %2$s', 'wc-partial-shipment'),
					'decrease'      => __('Decrease', 'wc-partial-shipment'),
					'increase'      => __('Increase', 'wc-partial-shipment'),
					'ship_all'      => __('Ship everything', 'wc-partial-shipment'),
					'clear_all'     => __('Clear all', 'wc-partial-shipment'),
					'cancel'        => __('Cancel', 'wc-partial-shipment'),
					'save'          => __('Save shipment', 'wc-partial-shipment'),
					'saving'        => __('Saving…', 'wc-partial-shipment'),
					'save_error'    => __('The shipment could not be saved. Please try again.', 'wc-partial-shipment'),
				),
			);
			wp_localize_script('wxp_partial_ship_script', 'wxp_partial_ship', $js_array);
			wp_enqueue_script('wxp_partial_ship_script');
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Only reading the settings tab to enqueue assets; no form data is processed.
		} elseif ('woocommerce_page_wc-settings' === $screen->id && isset($_GET['tab']) && 'wxp_partial_shipping_settings' === sanitize_key(wp_unslash($_GET['tab']))) {
			// Ensure WooCommerce's selectWoo (select2) assets are present so the
			// backfill status multiselect renders with the native WC styling.
			if (wp_style_is('woocommerce_admin_styles', 'registered')) {
				wp_enqueue_style('woocommerce_admin_styles');
			}
			if (wp_script_is('wc-enhanced-select', 'registered')) {
				wp_enqueue_script('wc-enhanced-select');
			}
			// admin-style.css carries the status badges shown in the preview.
			wp_enqueue_style('wxp_style', plugins_url('', __FILE__) . '/assets/css/admin-style.css', array(), WXP_PARTIAL_SHIP_VER);
			wp_enqueue_style('wxp_settings_style', plugins_url('', __FILE__) . '/assets/css/admin-settings.css', array('wxp_style'), WXP_PARTIAL_SHIP_VER);
			wp_enqueue_script('wxp_settings_script', plugins_url('', __FILE__) . '/assets/js/admin-settings.js', array('jquery'), WXP_PARTIAL_SHIP_VER, true);
		}
	}

	function wxp_order_item_headers($order)
	{
		echo '<th class="wxp-partital-item-head wxp-status-head">' . esc_html__('Shipment Status', 'wc-partial-shipment') . '</th>';
		echo '<th class="wxp-partital-item-head wxp-manage-head"><span class="dashicons dashicons-edit" aria-hidden="true"></span><span class="screen-reader-text">' . esc_html__('Manage Shipment', 'wc-partial-shipment') . '</span></th>';
	}

	function wxp_order_item_values($product, $item, $item_id)
	{

		// Product lines only (shipping/fee/refund rows pass no product). A line whose
		// product was deleted since the order was placed still gets its status.
		if (is_a($item, 'WC_Order_Item_Product')) {
			$order_id = $item->get_order_id();
			$order    = wc_get_order($order_id);
			$icon     = $this->get_item_status_icon_html($item, $order);
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- HTML generated internally and sanitized.
			echo '<td class="wxp-partital-item-icon wxp-status-col">' . $icon . '</td>';
			echo '<td class="wxp-partital-line-item"><a href="javascript:void(0);" data-check="' . esc_attr(wp_create_nonce('wxp_check_' . $order_id)) . '" data-item-id="' . esc_attr($item_id) . '" data-order-id="' . esc_attr($order_id) . '" title="' . esc_html__('Manage Shipment', 'wc-partial-shipment') . '" class="wxp-icons icon-wxp-set-shipping"></a></td>';
		} else {
			echo '<td></td>';
			echo '<td></td>';
		}
	}

	function wxp_order_shipment_button($order)
	{
		echo '<button type="button" data-check="' . esc_attr(wp_create_nonce('wxp_check_' . $order->get_id())) . '" data-order-id="' . esc_attr($order->get_id()) . '" class="button wxp-order-shipment">' . esc_html__('Shipment', 'wc-partial-shipment') . '</button>';
	}

	function wxp_order_shipment()
	{

		if (! current_user_can('manage_woocommerce')) {
			wp_send_json(array('valid' => false));
		}

		$order_id = absint(wp_unslash($_POST['order_id'] ?? 0));

		check_ajax_referer(
			'wxp_check_' . $order_id,
			'wxp_check'
		);

		$valid = false;
		$init = false;
		$products = array();
		$order_number = '';

		if ($order_id) {
			$order = wc_get_order($order_id);
			if (is_a($order, 'WC_Order')) {
				$order_number = $order->get_order_number();
				$wxp_shipment = $this->get_wxp_shipment_data($order_id);
				$init = is_array($wxp_shipment) && !empty($wxp_shipment) ? true : false;
				$items = $order->get_items();
				if (is_array($items) && !empty($items)) {
					$valid = true;
					foreach ($items as $item) {
						$products[] = $this->wxp_modal_item_data($order, $item, $wxp_shipment);
					}
				}
			}
		}
		wp_send_json(array('order_id' => $order_id, 'order_number' => $order_number, 'valid' => $valid, 'products' => $products, 'init' => $init, 'check' => wp_create_nonce('wxp_check_' . $order_id)));
	}

	function wxp_order_item_shipment()
	{

		if (! current_user_can('manage_woocommerce')) {
			wp_send_json(array('valid' => false));
		}

		$order_id = isset($_POST['order_id'])
			? absint(wp_unslash($_POST['order_id']))
			: 0;

		check_ajax_referer(
			'wxp_check_' . $order_id,
			'wxp_check'
		);


		$products = array();
		$valid = false;
		$order_number = '';
		$item_id  = intval($_POST['item_id'] ?? 0);
		if ($order_id && $item_id) {
			$item  = WC_Order_Factory::get_order_item($item_id);
			$item_order = wc_get_order($order_id);
			if (is_a($item, 'WC_Order_Item_Product') && (int) $item->get_order_id() === (int) $order_id && is_a($item_order, 'WC_Order')) {
				$valid = true;
				$order_number = $item_order->get_order_number();
				$products[] = $this->wxp_modal_item_data($item_order, $item, $this->get_wxp_shipment_data($order_id));
			}
		}

		wp_send_json(array('order_id' => $order_id, 'order_number' => $order_number, 'item_id' => $item_id, 'valid' => $valid, 'products' => $products, 'check' => wp_create_nonce('wxp_check_' . $order_id)));
	}

	/**
	 * One item row for the Shipment popup.
	 *
	 * @param WC_Order              $order        Order.
	 * @param WC_Order_Item_Product $item         Order item.
	 * @param array                 $wxp_shipment Shipped quantities by item id (get_wxp_shipment_data()).
	 * @return array
	 */
	function wxp_modal_item_data($order, $item, $wxp_shipment)
	{
		$item_id  = $item->get_id();
		$product  = $item->get_product();
		$ordered  = (int) $item->get_quantity();
		$refunded = abs((int) $order->get_qty_refunded_for_item($item_id));
		$net_qty  = $this->wxp_get_item_net_qty($order, $item_id, $ordered);
		$shipped  = isset($wxp_shipment[$item_id]['item_qty']) ? (int) $wxp_shipment[$item_id]['item_qty'] : 0;

		return array(
			'id'           => $item_id,
			'item_id'      => $item_id,
			'name'         => $item->get_name(),
			'sku'          => is_a($product, 'WC_Product') ? (string) $product->get_sku() : '',
			'image'        => $this->wxp_item_image_url($product),
			'virtual'      => is_a($product, 'WC_Product') ? $product->is_virtual() : false,
			'ordered'      => $ordered,
			'refunded_qty' => min($refunded, $ordered),
			'qty'          => $net_qty,
			'shipped'      => min($shipped, $net_qty), // a legacy over-record never exceeds what is still owed
			'refunded'     => ($ordered > 0 && $refunded >= $ordered),
			'order_id'     => $order->get_id(),
		);
	}

	/**
	 * Thumbnail URL for the Shipment popup (variation, then parent product, then the WooCommerce placeholder).
	 *
	 * @param WC_Product|false|null $product Product.
	 * @return string
	 */
	function wxp_item_image_url($product)
	{
		$image_id = 0;
		if (is_a($product, 'WC_Product')) {
			$image_id = (int) $product->get_image_id();
			if (! $image_id && $product->get_parent_id()) {
				$parent   = wc_get_product($product->get_parent_id());
				$image_id = $parent ? (int) $parent->get_image_id() : 0;
			}
		}
		$url = $image_id ? wp_get_attachment_image_url($image_id, 'thumbnail') : '';
		return esc_url_raw($url ? $url : wc_placeholder_img_src('thumbnail'));
	}

	function wxp_order_set_shipped()
	{

		if (! current_user_can('manage_woocommerce')) {
			wp_send_json(array('valid' => false));
		}

		$order_id = isset($_POST['order_id'])
			? absint(wp_unslash($_POST['order_id']))
			: 0;

		check_ajax_referer(
			'wxp_check_' . $order_id,
			'wxp_check'
		);


		$order = wc_get_order($order_id);
		$status_key = '';

		if ($order_id && is_a($order, 'WC_Order')) {
			$shipped_items = array();

			if (isset($_POST['shipped']) && is_array($_POST['shipped'])) {
				$shipped_items = map_deep(wp_unslash($_POST['shipped']), 'sanitize_text_field');
			}

			foreach ($shipped_items as $shipped_item) {
				$item_id = isset($shipped_item['item_id']) ? intval($shipped_item['item_id']) : 0;
				// get_item() loads any item from the database, so check it is a product line of this order.
				$order_item = $item_id ? $order->get_item($item_id) : null;
				if (! is_a($order_item, 'WC_Order_Item_Product') || (int) $order_item->get_order_id() !== $order_id) {
					continue; // unknown item, a shipping/fee line or an item of another order
				}

				$type = isset($shipped_item['type']) ? $shipped_item['type'] : 'shipped';
				$qty  = isset($shipped_item['shipped']) ? intval($shipped_item['shipped']) : 0;

				if ('not-shipped' === $type) {
					$qty = 0;
				} else {
					// Refund-aware: never store more than the net shippable qty.
					$qty = min(max(0, $qty), $this->wxp_get_item_net_qty($order, $item_id, $order_item->get_quantity()));
				}

				$this->wxp_set_item_shipped_qty($order_id, $item_id, $qty);
			}

			if (is_a($order, 'WC_Order')) {
				$order->update_meta_data('_init_wxp_shipment', 1);
				$order->save();
				// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Custom plugin hook.
				do_action('wxp_order_status', $order_id);
				$order = wc_get_order($order_id);
				$status_key = $order->get_status();
				$status_key = wc_is_order_status('wc-' . $status_key) ? 'wc-' . $status_key : $status_key;
			}
		}

		wp_send_json(array('order_id' => $order_id, 'status' => $status_key));
	}

	function get_shipment_id($order_id)
	{
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin table.
		$shipment_id = $wpdb->get_var($wpdb->prepare("SELECT id as ship_id FROM " . $wpdb->prefix . "partial_shipment WHERE order_id=%d ORDER BY id ASC LIMIT 1", $order_id));
		return $shipment_id;
	}

	/**
	 * Store the total shipped quantity of one order item.
	 *
	 * This plugin keeps one shipment row per order, but the premium "Advance
	 * Partial Shipment" plugin (which shares these tables) stores one row per
	 * parcel. After switching back from premium, the item total is spread over
	 * several rows: the first row absorbs the difference and, when the new total
	 * is lower than the other parcels, the most recent parcels are reduced first.
	 *
	 * @param int $order_id Order id.
	 * @param int $item_id  Order item id.
	 * @param int $qty      New total shipped quantity.
	 */
	function wxp_set_item_shipped_qty($order_id, $item_id, $qty)
	{
		global $wpdb;
		$this->wxp_flush_shipment_cache();
		$qty         = max(0, (int) $qty);
		$shipment_id = (int) $this->get_or_create_shipment_id($order_id);
		if (! $shipment_id) {
			return;
		}
		$items_table = $wpdb->prefix . 'partial_shipment_items';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin table.
		$rows = $wpdb->get_results($wpdb->prepare(
			"SELECT i.id, i.shipment_id, i.item_qty FROM {$wpdb->prefix}partial_shipment_items i INNER JOIN {$wpdb->prefix}partial_shipment s ON s.id = i.shipment_id WHERE s.order_id = %d AND i.item_id = %s ORDER BY i.shipment_id DESC, i.id DESC",
			$order_id,
			(string) $item_id
		));

		$primary_row = null;
		$others      = array();
		$others_sum  = 0;
		foreach ((array) $rows as $row) {
			if ((int) $row->shipment_id === $shipment_id && null === $primary_row) {
				$primary_row = $row;
			} else {
				$others[]    = $row;
				$others_sum += (int) $row->item_qty;
			}
		}

		$primary_qty = $qty - $others_sum;
		if ($primary_qty < 0) {
			// Reduce the latest parcels first.
			$excess = -$primary_qty;
			foreach ($others as $row) {
				if ($excess <= 0) {
					break;
				}
				$cut = min((int) $row->item_qty, $excess);
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin table.
				$wpdb->update($items_table, array('item_qty' => (int) $row->item_qty - $cut), array('id' => (int) $row->id), array('%d'), array('%d'));
				$excess -= $cut;
			}
			$primary_qty = 0;
		}

		if ($primary_row) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin table.
			$wpdb->update($items_table, array('item_qty' => $primary_qty), array('id' => (int) $primary_row->id), array('%d'), array('%d'));
		} elseif ($primary_qty > 0) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin table.
			$wpdb->insert(
				$items_table,
				array(
					'shipment_id' => $shipment_id,
					'item_id'     => $item_id,
					'item_qty'    => $primary_qty,
				),
				array('%d', '%d', '%d')
			);
		}
		$this->wxp_flush_shipment_cache();
	}

	/**
	 * Fetch the shipment row id for an order, creating it if it doesn't exist yet.
	 *
	 * The `order_id` column has a UNIQUE key, so if two requests race to create
	 * the row for the same order, the losing insert fails and we simply re-fetch
	 * the id the winner just created instead of ending up with duplicate rows.
	 *
	 * @param int $order_id Order id.
	 * @return int|false Shipment row id, or false on failure.
	 */
	function get_or_create_shipment_id($order_id)
	{
		global $wpdb;

		$shipment_id = $this->get_shipment_id($order_id);
		if ($shipment_id) {
			return $shipment_id;
		}

		$data = array(
			'order_id' => $order_id,
			'shipment_id' => 1,
			'shipment_url' => '',
			'shipment_num' => '',
			'shipment_date' => current_time('timestamp'),
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin table.
		$inserted = $wpdb->insert($wpdb->prefix . "partial_shipment", $data, array('%d', '%d', '%s', '%s', '%s'));
		if ($inserted) {
			return $wpdb->insert_id;
		}

		// Insert failed, most likely a concurrent request already created the row.
		return $this->get_shipment_id($order_id);
	}

	function get_wxp_shipment_data($order_id)
	{
		global $wpdb;
		$order_id = (int) $order_id;
		if (isset($this->shipment_data_cache[$order_id])) {
			return $this->shipment_data_cache[$order_id];
		}
		$shipment = array();
		// Sum over every shipment row of the order (one row here, possibly several
		// when the data was created by the premium plugin).
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin table.
		$shipment_items = $wpdb->get_results($wpdb->prepare(
			"SELECT i.item_id, SUM(i.item_qty) AS item_qty FROM {$wpdb->prefix}partial_shipment_items i INNER JOIN {$wpdb->prefix}partial_shipment s ON s.id = i.shipment_id WHERE s.order_id = %d GROUP BY i.item_id",
			$order_id
		), ARRAY_A);
		if (is_array($shipment_items) && !empty($shipment_items)) {
			foreach ($shipment_items as $item) {
				if (isset($item['item_id'])) {
					$item['item_qty'] = (int) $item['item_qty'];
					$shipment[$item['item_id']] = $item;
				}
			}
		}
		$this->shipment_data_cache[$order_id] = $shipment;
		return $shipment;
	}

	/** Forget the shipped quantities remembered for this request (after any write to the shipment tables). */
	function wxp_flush_shipment_cache()
	{
		$this->shipment_data_cache = array();
	}

	/**
	 * Net shippable quantity for an order item: ordered qty minus any refunded qty.
	 *
	 * WooCommerce reports refunded quantity as a negative number via
	 * WC_Order::get_qty_refunded_for_item(), so we take its absolute value.
	 *
	 * @param WC_Order $order    Order object.
	 * @param int      $item_id  Order item id.
	 * @param int      $ordered  Ordered quantity for the item.
	 * @return int
	 */
	function wxp_get_item_net_qty($order, $item_id, $ordered)
	{
		if (! is_a($order, 'WC_Order')) {
			return (int) $ordered;
		}
		$refunded = abs((int) $order->get_qty_refunded_for_item($item_id));
		return max(0, (int) $ordered - $refunded);
	}

	/**
	 * Compute the shipment state for a single order item.
	 *
	 * @param WC_Order_Item_Product $item  Order item.
	 * @param WC_Order              $order Order object.
	 * @return array {state: shipped|partial|not, shipped: int, total: int}
	 */
	function get_item_ship_state($item, $order)
	{
		$order_id = is_a($item, 'WC_Order_Item_Product') ? $item->get_order_id() : 0;
		$product  = is_callable(array($item, 'get_product')) ? $item->get_product() : null;
		$item_id  = $item->get_id();
		$item_data = $item->get_data();
		$ordered  = isset($item_data['quantity']) ? (int) $item_data['quantity'] : 0;
		// Raw refunded quantity (WC reports it as a negative number).
		$refunded = is_a($order, 'WC_Order') ? abs((int) $order->get_qty_refunded_for_item($item_id)) : 0;
		// Refund-aware: the quantity still owed is ordered minus refunded.
		$total    = $this->wxp_get_item_net_qty($order, $item_id, $ordered);

		// Fully refunded: show the Refunded state instead of Not Shipped - 0.
		if ($ordered > 0 && $refunded >= $ordered) {
			return array('state' => 'refunded', 'shipped' => $ordered, 'total' => $total);
		}

		$state    = 'not';
		$shipped  = 0;

		if (is_a($product, 'WC_Product') && $product->is_virtual()) {
			$state   = 'shipped';
			$shipped = $total;
		} else {
			// Physical item, or a line whose product has since been deleted (still shippable).
			$wxp_shipments = $this->get_wxp_shipment_data($order_id);
			if ($item_id && array_key_exists($item_id, $wxp_shipments)) {
				// Cap the stored qty so a legacy over-record can't exceed the net.
				$q = min(
					isset($wxp_shipments[$item_id]['item_qty']) ? (int) $wxp_shipments[$item_id]['item_qty'] : 0,
					$total
				);
				if ($q > 0 && $total > 0 && $q < $total) {
					$state = 'partial';
				} elseif ($q > 0 && $total > 0 && $q >= $total) {
					$state = 'shipped';
				} else {
					$state = 'not';
				}
				$shipped = $q;
			}
		}

		return array('state' => $state, 'shipped' => $shipped, 'total' => $total);
	}

	/**
	 * Build the status icon HTML for an order item (admin columns / front / popup).
	 *
	 * @param WC_Order_Item_Product $item  Order item.
	 * @param WC_Order              $order Order object.
	 * @return string
	 */
	function get_item_status_icon_html($item, $order)
	{
		$state = $this->get_item_ship_state($item, $order);
		$count = 'not' === $state['state'] ? $state['total'] : $state['shipped'];
		return $this->get_badge_html($state['state'], $count, $state['total']);
	}

	/**
	 * Status badge markup, shared by the admin/front badges and the settings preview.
	 *
	 * @param string $state shipped|partial|refunded|not.
	 * @param int    $count Number shown on the badge.
	 * @param int    $total Quantity still owed (used in the tooltip).
	 * @return string
	 */
	function get_badge_html($state, $count, $total)
	{
		$labels = $this->wc_partial_labels;
		$map    = array(
			'shipped'  => array('wxp-shipped', 'shipped'),
			'partial'  => array('wxp-partial-shipped', 'partially-shipped'),
			'refunded' => array('wxp-refunded', 'refunded'),
			'not'      => array('wxp-not-shipped', 'not-shipped'),
		);
		list($cls, $key) = isset($map[$state]) ? $map[$state] : $map['not'];
		$label = isset($labels[$key]) ? $labels[$key] : $key;
		$title = $label . ': ' . $count . '/' . $total;

		return '<a href="javascript:void(0);" class="wxp-top" title="' . esc_attr($title) . '"><span class="' . esc_attr($cls) . ' wxp-ship-status" title="' . esc_attr($label) . '">' . esc_html($label) . ' - ' . esc_html($count) . '</span></a>';
	}

	// Front End Status
	function wxp_order_item_icons($item_id, $item, $order, $bol = false)
	{
		// This hook also runs in emails and on the thank-you page; only the View Order page shows the badge.
		if (! is_view_order_page() || ! is_a($item, 'WC_Order_Item_Product')) {
			return;
		}

		$icon = '';
		$show = true;
		if (is_a($order, 'WC_Order')) {
			$init = $order->get_meta('_init_wxp_shipment');
			if (isset($this->wc_partial_shipment_settings['partially_hide_status']) && $this->wc_partial_shipment_settings['partially_hide_status'] == 'yes' && $init != '1') {
				$show = false;
			}
			if ($show) {
				$icon = $this->get_item_status_icon_html($item, $order);
			}
		}

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- HTML generated internally and sanitized.
		echo '<div class="wxp-order-item-ship-status">' . $icon . '</div>';
	}

	function add_partial_complete_status($statuses)
	{
		if (isset($this->wc_partial_shipment_settings['partially_shipped_status'])) {
			if ($this->wc_partial_shipment_settings['partially_shipped_status'] == 'yes') {
				$statuses['wc-partial-shipped'] = __('Partially Shipped', 'wc-partial-shipment');
			}
		}
		return $statuses;
	}

	function wxp_order_status_in_popup($columns, $order)
	{
		if (isset($this->wc_partial_shipment_settings['partially_enable_status_popup'])) {
			if ($this->wc_partial_shipment_settings['partially_enable_status_popup'] == 'yes') {
				$columns['wxp_status'] = __('Status', 'wc-partial-shipment');
			}
		}
		return $columns;
	}

	function wxp_partial_complete_register_status()
	{
		if (isset($this->wc_partial_shipment_settings['partially_shipped_status'])) {
			if ($this->wc_partial_shipment_settings['partially_shipped_status'] == 'yes') {
				register_post_status('wc-partial-shipped', array(
					'label' => __('Partially Shipped', 'wc-partial-shipment'),
					'public' => true,
					'exclude_from_search' => false,
					'show_in_admin_all_list' => true,
					'show_in_admin_status_list' => true,
					/* translators: %s: Number of items partially shipped. */
					'label_count' => _n_noop('Partially Shipped <span class="count">(%s)</span>', 'Partially Shipped <span class="count">(%s)</span>', 'wc-partial-shipment')
				));
			}
		}
	}

	function wxp_order_status_in_popup_value($val, $item, $item_id, $order)
	{
		if (isset($this->wc_partial_shipment_settings['partially_enable_status_popup'])) {
			if ($this->wc_partial_shipment_settings['partially_enable_status_popup'] == 'yes') {
				// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- HTML generated internally and sanitized.
				$val = $this->get_item_status_icon_html($item, $order);
			}
		}
		return $val;
	}

	function wxp_shipment_mail($actions)
	{
		$actions['wxp_partial_shipment'] = __('Partial shipment notification', 'wc-partial-shipment');
		return $actions;
	}

	function trigger_wxp_shipment_mail($order)
	{
		WC()->payment_gateways();
		WC()->shipping();
		$emails = WC()->mailer()->get_emails();
		$email  = isset($emails['WC_Email_Customer_Partial_shipment']) ? $emails['WC_Email_Customer_Partial_shipment'] : null;
		$sent   = $email ? $email->trigger($order->get_id(), $order) : false;

		if ($sent) {
			$order->add_order_note(__('Partial order details manually sent to customer.', 'wc-partial-shipment'), false, true);
			add_filter('redirect_post_location', array($this, 'set_email_sent_message'));
		} else {
			$order->add_order_note(__('Partial shipment email was not sent. Check that it is enabled under WooCommerce > Settings > Emails and that the order has a billing email address.', 'wc-partial-shipment'), false, true);
		}
	}

	function set_email_sent_message($location)
	{
		return add_query_arg('message', 11, $location);
	}

	function wxp_shipment_email_class($emails)
	{
		$emails['WC_Email_Customer_Partial_shipment'] = include dirname(__FILE__) . '/inc/class-wc-email-partial-shipment.php';
		return $emails;
	}

	function order_details($order, $sent_to_admin = false, $plain_text = false, $email = '')
	{

		if ($plain_text) {
			wc_get_template(
				'emails/plain/email-partial-order-details.php',
				array(
					'order'         => $order,
					'sent_to_admin' => $sent_to_admin,
					'plain_text'    => $plain_text,
					'email'         => $email,
				),
				'',
				WXP_PARTIAL_SHIP_DIR . '/'
			);
		} else {
			wc_get_template(
				'emails/email-partial-order-details.php',
				array(
					'order'         => $order,
					'sent_to_admin' => $sent_to_admin,
					'plain_text'    => $plain_text,
					'email'         => $email,
				),
				'',
				WXP_PARTIAL_SHIP_DIR . '/'
			);
		}
	}

	function wc_get_email_partial_order_items($order, $args = array())
	{
		ob_start();

		$defaults = array(
			'show_sku'      => false,
			'show_image'    => false,
			'image_size'    => array(32, 32),
			'plain_text'    => false,
			'sent_to_admin' => false,
		);

		$args     = wp_parse_args($args, $defaults);
		$template = $args['plain_text'] ? 'emails/plain/email-partial-order-items.php' : 'emails/email-partial-order-items.php';

		wc_get_template(
			$template,
			apply_filters('woocommerce_email_order_items_args', array(
				'order'               => $order,
				'items'               => $order->get_items(),
				'show_download_links' => $order->is_download_permitted() && ! $args['sent_to_admin'],
				'show_sku'            => $args['show_sku'],
				'show_purchase_note'  => $order->is_paid() && ! $args['sent_to_admin'],
				'show_image'          => $args['show_image'],
				'image_size'          => $args['image_size'],
				'plain_text'          => $args['plain_text'],
				'sent_to_admin'       => $args['sent_to_admin'],
			)),
			'',
			WXP_PARTIAL_SHIP_DIR . '/'
		);

		return apply_filters('woocommerce_email_order_items_table', ob_get_clean(), $order);
	}

	function get_item_status($item_id, $item, $order)
	{
		$state = $this->get_item_ship_state($item, $order);

		if ('shipped' === $state['state']) {
			$label = __('Shipped', 'wc-partial-shipment');
			$count = $state['shipped'];
		} elseif ('partial' === $state['state']) {
			$label = __('Partially Shipped', 'wc-partial-shipment');
			$count = $state['shipped'];
		} elseif ('refunded' === $state['state']) {
			$label = __('Refunded', 'wc-partial-shipment');
			$count = $state['shipped'];
		} else {
			$label = __('Not Shipped', 'wc-partial-shipment');
			$count = $state['total'];
		}

		return $label . ' X ' . $count;
	}

	function wxp_order_status_update($order_id)
	{
		$order = wc_get_order($order_id);
		if (! is_a($order, 'WC_Order')) {
			return;
		}

		// Never revive a cancelled, refunded or failed order from the shipment screen, and never turn an unpaid
		// (Pending payment) order into a paid-status one (Partially Shipped / Completed count as paid).
		if ($order->has_status(array('cancelled', 'refunded', 'failed', 'trash', 'checkout-draft', 'pending'))) {
			return;
		}

		$total_count   = 0;
		$shipped_count = 0;
		$wxp_shipment  = $this->get_wxp_shipment_data($order_id);
		foreach ($order->get_items() as $item) {
			$product = is_callable(array($item, 'get_product')) ? $item->get_product() : null;
			if (is_a($product, 'WC_Product') && $product->is_virtual()) {
				continue; // virtual items never need shipping (deleted products still count)
			}
			$net     = $this->wxp_get_item_net_qty($order, $item->get_id(), $item->get_quantity());
			$shipped = isset($wxp_shipment[$item->get_id()]['item_qty']) ? (int) $wxp_shipment[$item->get_id()]['item_qty'] : 0;
			$total_count   += $net;
			$shipped_count += min($net, $shipped); // refunds after shipping never over-count
		}

		if ($total_count > 0 && $shipped_count >= $total_count && wc_is_order_status('wc-completed')) {
			if ($this->wc_partial_shipment_settings['partially_auto_complete'] == 'yes' && ! $order->has_status('completed')) {
				$order->update_status('completed', __('Order Completed by Woocommerce Partial Shipment.', 'wc-partial-shipment'));
			}
		} elseif ($total_count > 0 && $shipped_count < 1 && wc_is_order_status('wc-processing')) {
			// Only undo a status this plugin sets. Moving Pending/On hold to Processing
			// would mark an unpaid order as paid and email the customer.
			if ($order->has_status(array('partial-shipped', 'completed'))) {
				$order->update_status('processing', __('Order Processed by Woocommerce Partial Shipment.', 'wc-partial-shipment'));
			}
		} elseif ($total_count > 0 && $shipped_count > 0 && $shipped_count < $total_count && wc_is_order_status('wc-partial-shipped')) {
			if ($this->wc_partial_shipment_settings['partially_shipped_status'] == 'yes' && ! $order->has_status('partial-shipped')) {
				$order->update_status('partial-shipped', __('Order Partially Shipped by Woocommerce Partial Shipment.', 'wc-partial-shipment'));
			}
		}
	}

	/**
	 * After a refund, re-check a partially shipped order: when the refunded units
	 * were the ones still waiting to ship, the order is now fully shipped.
	 *
	 * @param int $order_id  Order id.
	 * @param int $refund_id Refund id.
	 */
	function wxp_order_status_after_refund($order_id, $refund_id = 0)
	{
		$order = wc_get_order($order_id);
		if (is_a($order, 'WC_Order') && $order->has_status('partial-shipped') && $this->get_shipment_id($order_id)) {
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Custom plugin hook.
			do_action('wxp_order_status', $order_id);
		}
	}

	/**
	 * Treat "Partially Shipped" as a paid status, like Processing and Completed, so the
	 * customer's total spent, "verified owner" reviews and paid-order checks keep counting it.
	 *
	 * @param array $statuses Paid statuses (without the wc- prefix).
	 * @return array
	 */
	function wxp_paid_statuses($statuses)
	{
		$statuses   = (array) $statuses;
		$statuses[] = 'partial-shipped';
		return array_values(array_unique($statuses));
	}

	/**
	 * Keep downloads available while an order is Partially Shipped, on the same
	 * terms as Processing (WooCommerce's "grant access after payment" setting).
	 *
	 * @param bool     $permitted Whether downloads are permitted.
	 * @param WC_Order $order     Order object.
	 * @return bool
	 */
	function wxp_download_permitted($permitted, $order)
	{
		if (! $permitted && is_a($order, 'WC_Order') && $order->has_status('partial-shipped')) {
			$permitted = 'yes' === get_option('woocommerce_downloads_grant_access_after_payment');
		}
		return $permitted;
	}

	function wxp_order_status_switch($order_id)
	{
		$order = wc_get_order($order_id);
		if (is_a($order, 'WC_Order')) {
			foreach ($order->get_items() as $item) {
				// Refund-aware: never mark more as shipped than the net qty still owed.
				$this->wxp_set_item_shipped_qty($order_id, $item->get_id(), $this->wxp_get_item_net_qty($order, $item->get_id(), $item->get_quantity()));
			}
			$order->update_meta_data('_init_wxp_shipment', 1);
			$order->save_meta_data();
		}
	}

	/**
	 * Backfill shipment records for past orders of one or more chosen statuses
	 * that have none. Intended for orders that were already shipped (e.g.
	 * Completed) before this plugin was installed and therefore never fired
	 * the woocommerce_order_status_completed transition hook.
	 *
	 * Orders are processed in batches: the first batch runs in this request (so
	 * a small store is done by the time the settings page reloads) and the rest
	 * continue in the background with Action Scheduler, so large stores never
	 * time out. Only orders lacking a partial_shipment row are touched, so it is
	 * safe to run repeatedly and never overwrites a merchant's manual edits.
	 * Per-item quantities are refund-aware (net qty) via wxp_order_status_switch().
	 */
	function wxp_backfill_orders()
	{
		if (! current_user_can('manage_woocommerce')) {
			wp_die(esc_html__('You are not allowed to do this.', 'wc-partial-shipment'));
		}

		check_admin_referer('wxp_backfill_shipments', 'wxp_backfill_nonce');

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce is verified by check_admin_referer() above.
		$raw = isset($_REQUEST['wxp_backfill_status']) ? sanitize_text_field(wp_unslash($_REQUEST['wxp_backfill_status'])) : 'wc-completed';
		if (is_string($raw)) {
			$raw = explode(',', $raw);
		}
		$allowed     = wc_get_order_statuses();
		$statuses    = array();
		$status_list = array();
		foreach ((array) $raw as $candidate) {
			$candidate = sanitize_key($candidate);
			if (array_key_exists($candidate, $allowed)) {
				$statuses[]    = $candidate;
				$status_list[] = $allowed[$candidate];
			}
		}
		if (empty($statuses)) {
			$statuses    = array('wc-completed');
			$status_list = array(isset($allowed['wc-completed']) ? $allowed['wc-completed'] : __('Completed', 'wc-partial-shipment'));
		}

		// A run already in progress keeps going; clicking again does not restart it.
		if (! $this->wxp_backfill_get_job()) {
			$job = array(
				'run_id'       => uniqid('wxp_', true),
				'statuses'     => $statuses,
				'status_label' => implode(', ', $status_list),
				'offset'       => 0,
				'processed'    => 0,
				'skipped'      => 0,
				'updated'      => time(),
			);
			update_option(self::BACKFILL_JOB, $job, false);
			$this->wxp_backfill_batch($job['run_id']);
		}

		$redirect = admin_url('admin.php?page=wc-settings&tab=wxp_partial_shipping_settings');
		wp_safe_redirect($redirect);
		exit();
	}

	/**
	 * The backfill run in progress, or null. A run whose last batch is older than
	 * 30 minutes (e.g. Action Scheduler stopped) counts as abandoned, so a new
	 * click can start over.
	 *
	 * @return array|null
	 */
	function wxp_backfill_get_job()
	{
		$job = get_option(self::BACKFILL_JOB);
		if (! is_array($job) || empty($job['run_id']) || empty($job['statuses'])) {
			return null;
		}
		if (time() - (int) $job['updated'] > 30 * MINUTE_IN_SECONDS) {
			return null;
		}
		return $job;
	}

	/**
	 * Action Scheduler callback: process one batch of the backfill run and queue
	 * the next one. Without Action Scheduler the batches run one after another.
	 *
	 * @param string $run_id Id of the run the queued action belongs to.
	 */
	function wxp_backfill_batch($run_id = '')
	{
		do {
			$more = $this->wxp_backfill_step((string) $run_id);
		} while ($more && ! function_exists('as_enqueue_async_action'));

		if ($more) {
			as_enqueue_async_action(self::BACKFILL_HOOK, array((string) $run_id), self::BACKFILL_GROUP);
		}
	}

	/**
	 * Backfill one batch of orders.
	 *
	 * Orders are walked by ascending id with an offset. Backfilling never changes
	 * an order's status, so the offset stays valid between batches.
	 *
	 * @param string $run_id Id of the run to continue.
	 * @return bool Whether more orders remain.
	 */
	function wxp_backfill_step($run_id)
	{
		$job = get_option(self::BACKFILL_JOB);
		if (! is_array($job) || empty($job['run_id']) || $job['run_id'] !== $run_id) {
			return false; // finished, restarted or cancelled
		}

		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Custom plugin hook.
		$limit     = max(1, (int) apply_filters('wxp_backfill_batch_size', self::BACKFILL_BATCH));
		$order_ids = wc_get_orders(array(
			'status'  => $job['statuses'],
			'type'    => 'shop_order',
			'limit'   => $limit,
			'offset'  => (int) $job['offset'],
			'orderby' => 'ID',
			'order'   => 'ASC',
			'return'  => 'ids',
		));
		$order_ids = is_array($order_ids) ? $order_ids : array();

		foreach ($order_ids as $order_id) {
			if ($this->get_shipment_id($order_id)) {
				$job['skipped']++;
				continue;
			}
			$this->wxp_order_status_switch($order_id);
			$job['processed']++;
		}
		$job['offset'] += count($order_ids);
		$job['updated'] = time();

		if (count($order_ids) < $limit) {
			delete_option(self::BACKFILL_JOB);
			$result = array(
				'processed'    => $job['processed'],
				'skipped'      => $job['skipped'],
				'status_label' => $job['status_label'],
			);
			update_option('wxp_backfill_notice', $result, false);
			// Kept for the settings page ("Last run: ...").
			update_option('wxp_backfill_last', $result + array('finished' => time()), false);
			return false;
		}

		update_option(self::BACKFILL_JOB, $job, false);
		return true;
	}

	/**
	 * Stop a running backfill when the plugin is deactivated (records already
	 * created are kept).
	 */
	function partial_shipment_deactivate()
	{
		if (function_exists('as_unschedule_all_actions')) {
			as_unschedule_all_actions(self::BACKFILL_HOOK, array(), self::BACKFILL_GROUP);
		}
		delete_option(self::BACKFILL_JOB);
	}

	/**
	 * Show the result of a finished backfill run once, and the progress of a
	 * running one on the plugin's settings tab.
	 */
	function wxp_backfill_admin_notice()
	{
		if (! current_user_can('manage_woocommerce')) {
			return;
		}

		$notice = get_option('wxp_backfill_notice');
		if (is_array($notice)) {
			delete_option('wxp_backfill_notice');

			$status_label = isset($notice['status_label']) ? $notice['status_label'] : __('Completed', 'wc-partial-shipment');

			$message = sprintf(
				/* translators: 1: number of orders backfilled, 2: order status label(s), 3: number skipped (already had records). */
				esc_html__('Partial Shipment: backfilled %1$d order(s) with status "%2$s"; %3$d already had shipment records and were skipped.', 'wc-partial-shipment'),
				(int) $notice['processed'],
				esc_html($status_label),
				(int) $notice['skipped']
			);

			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $message is already escaped via esc_html__() and esc_html() during construction.
			echo '<div class="notice notice-success is-dismissible"><p>' . $message . '</p></div>';
		}

		$job    = $this->wxp_backfill_get_job();
		$screen = function_exists('get_current_screen') ? get_current_screen() : null;
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Only reading the current settings tab to decide whether to show a notice.
		$tab = isset($_GET['tab']) ? sanitize_key(wp_unslash($_GET['tab'])) : '';
		if ($job && $screen && 'woocommerce_page_wc-settings' === $screen->id && 'wxp_partial_shipping_settings' === $tab) {
			$message = sprintf(
				/* translators: 1: order status label(s), 2: number of orders backfilled so far, 3: number skipped so far. */
				esc_html__('Partial Shipment: backfilling orders with status "%1$s" in the background. %2$d order(s) backfilled and %3$d skipped so far.', 'wc-partial-shipment'),
				esc_html($job['status_label']),
				(int) $job['processed'],
				(int) $job['skipped']
			);
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $message is already escaped via esc_html__() and esc_html() during construction.
			echo '<div class="notice notice-info"><p>' . $message . '</p></div>';
		}
	}
}
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
function wxp_partial_shipment_init()
{
	return WXP_Partial_Shipment::instance();
}

/**
 * Whether a plugin is active on this site (single site, per-site or network wide).
 *
 * @param string $basename Plugin basename, e.g. "woocommerce/woocommerce.php".
 * @return bool
 */
function wxp_partial_shipment_is_plugin_active($basename) // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
{
	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core filter.
	$active = (array) apply_filters('active_plugins', get_option('active_plugins', array()));
	if (in_array($basename, $active, true)) {
		return true;
	}
	if (is_multisite()) {
		$network = (array) get_site_option('active_sitewide_plugins', array());
		return isset($network[$basename]);
	}
	return false;
}

/**
 * The premium "Advance Partial Shipment for WooCommerce" plugin replaces this one
 * and shares its database tables. When it is active this plugin stays idle (the
 * premium plugin also deactivates it on the next admin request), so both never
 * register the same order screens, settings tab and hooks at the same time.
 *
 * @return bool
 */
function wxp_partial_shipment_premium_active() // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
{
	return wxp_partial_shipment_is_plugin_active('wphub-partial-shipment/wphub-partial-shipment.php')
		|| wxp_partial_shipment_is_plugin_active('wc-partial-shipment-pro/woocommerce-partial-shipment-pro.php');
}

if (wxp_partial_shipment_premium_active()) {
	add_action('admin_notices', function () {
		// The current premium plugin shows its own notice when it switches this one off.
		if (current_user_can('activate_plugins') && ! class_exists('Wphub_Partial_Shipment')) {
			echo '<div class="notice notice-info"><p>' . esc_html__('Partial Shipment for WooCommerce is inactive because Advance Partial Shipment for WooCommerce is active and already includes all of its features. Your shipment data is shared between both plugins.', 'wc-partial-shipment') . '</p></div>';
		}
	});
} elseif (wxp_partial_shipment_is_plugin_active('woocommerce/woocommerce.php')) {
	wxp_partial_shipment_init();
}
