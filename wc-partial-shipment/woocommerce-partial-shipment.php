<?php

/**
 * Plugin Name: Partial Shipment for WooCommerce
 * Plugin URI: https://wpexpertshub.com/
 * Description: Add ability to partially ship an order.
 * Author: WpExperts Hub
 * Version: 3.6
 * Author URI: https://wpexpertshub.com/
 * Text Domain: wc-partial-shipment
 * License: GPLv3
 * Requires Plugins: woocommerce
 * Requires at least: 6.9
 * Requires PHP: 7.4
 * WC requires at least: 8.0
 * WC tested up to: 10.8.1
 **/


defined('ABSPATH') || exit;

class WXP_Partial_Shipment
{

	protected static $_instance = null;
	protected $wc_partial_labels = array();
	protected $wc_partial_shipment_settings = array();
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
			define('WXP_PARTIAL_SHIP_VER', '3.6');
		}
		if (!defined('WXP_PARTIAL_SHIP_DIR')) {
			define('WXP_PARTIAL_SHIP_DIR', __DIR__);
		}
		add_action('before_woocommerce_init', array($this, 'hpos_compatibility'));
		add_action('init', array($this, 'init_autoload'));
		add_action('init', array($this, 'autoload_classes'));
		add_action('init', array($this, 'load_settings'));
		add_action('init', array($this, 'wxp_partial_complete_register_status'), 999);
		add_filter('plugin_action_links_' . plugin_basename(__FILE__), array($this, 'wxp_partial_action_links'), 10, 1);
		register_activation_hook(__FILE__, array($this, 'partial_shipment_active'));

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

		// Backfill tool: create shipment rows for past orders (status selectable).
		add_action('admin_post_wxp_backfill_shipments', array($this, 'wxp_backfill_orders'));
		add_action('admin_notices', array($this, 'wxp_backfill_admin_notice'));
	}

	function hpos_compatibility()
	{
		if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('analytics', __FILE__, true);
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('new_navigation', __FILE__, true);
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('product_block_editor', __FILE__, true);
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('cart_checkout_blocks', __FILE__, true);
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('marketplace', __FILE__, true);
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('order_attribution', __FILE__, true);
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('hpos_fts_indexes', __FILE__, true);
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
		}
	}

	function partial_shipment_active()
	{
		include_once($this->plugin_path() . '/classes/wxp-partial-shipment-sql.php');
		$sql = new Wxp_Partial_Shipment_Sql();
		$sql->create();
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
		wp_enqueue_style('wxp_front_style', plugins_url('', __FILE__) . '/assets/css/front.css', array(), WXP_PARTIAL_SHIP_VER);
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
				'wxp_loader' => untrailingslashit(plugins_url('/', __FILE__)) . '/images/ajax-loader.gif',
				'wxp_ajax' => admin_url('admin-ajax.php'),
				'wxp_nonce' => wp_nonce_field('wxp_partial_shipment', 'wxp_partial_ship', false, false),
				'wxp_modal_title' => __('Partial Shipment', 'wc-partial-shipment'),
				'wxp_title' => __('Title', 'wc-partial-shipment'),
				'wxp_qty' => __('Quantity', 'wc-partial-shipment'),
				'wxp_ship' => __('Shipped', 'wc-partial-shipment'),
				'wxp_bulk_action' => __('Bulk Actions', 'wc-partial-shipment'),
				'wxp_bulk_mark_shipped' => __('Mark Shipped', 'wc-partial-shipment'),
				'wxp_bulk_mark_not_shipped' => __('Mark Unshipped', 'wc-partial-shipment'),
				'wxp_item_ship' => __('Mark Shipped', 'wc-partial-shipment'),
				'wxp_item_unship' => __('Mark Unshipped', 'wc-partial-shipment'),
				'wxp_refunded' => __('Refunded', 'wc-partial-shipment'),
				'wxp_update' => __('Update', 'wc-partial-shipment'),
				'wxp_order_nonce' => wp_create_nonce('order-item'),
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
		}
	}

	function wxp_order_item_headers($order)
	{
		echo '<th class="wxp-partital-item-head wxp-status-head">' . esc_html__('Shipment Status', 'wc-partial-shipment') . '</th>';
		echo '<th class="wxp-partital-item-head wxp-manage-head"><span class="dashicons dashicons-edit" aria-hidden="true"></span><span class="screen-reader-text">' . esc_html__('Manage Shipment', 'wc-partial-shipment') . '</span></th>';
	}

	function wxp_order_item_values($product, $item, $item_id)
	{

		if ($product) {
			$order_id = $item->get_order_id();
			$order    = wc_get_order($order_id);
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- HTML generated internally and sanitized.
			$icon = $this->get_item_status_icon_html($item, $order);
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

		if ($order_id) {
			$order = wc_get_order($order_id);
			if (is_a($order, 'WC_Order')) {
				$wxp_shipment = $this->get_wxp_shipment_data($order_id);
				$init = is_array($wxp_shipment) && !empty($wxp_shipment) ? true : false;
				$items = $order->get_items();
				if (is_array($items) && !empty($items)) {
					$valid = true;
					foreach ($items as $item) {
						$item_id = $item->get_id();
						$shipped = isset($wxp_shipment[$item_id]) ? $wxp_shipment[$item_id] : array('item_qty' => 0);
						$product = $item->get_product();
						$ordered = (int) $item->get_quantity();
						$refunded = abs((int) $order->get_qty_refunded_for_item($item_id));
						$net_qty = $this->wxp_get_item_net_qty($order, $item_id, $item->get_quantity());
						$products[] = array(
							'id' => $item->get_id(),
							'name' => $item->get_name(),
							'virtual' => is_a($product, 'WC_Product') ? $product->is_virtual() : false,
							'qty' => $net_qty,
							'shipped' => $shipped['item_qty'],
							'refunded' => ($ordered > 0 && $refunded >= $ordered),
							'order_id' => $order_id
						);
					}
				}
			}
		}
		echo json_encode(array('order_id' => $order_id, 'valid' => $valid, 'products' => $products, 'init' => $init, 'check' => wp_create_nonce('wxp_check_' . $order_id)));
		exit();
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
		$item_id  = intval($_POST['item_id'] ?? 0);
		if ($order_id && $item_id) {
			$wxp_shipment = $this->get_wxp_shipment_data($order_id);
			$item  = WC_Order_Factory::get_order_item($item_id);
			if (is_a($item, 'WC_Order_Item_Product')) {
				$valid = true;
				$item_data = $item->get_data();
				$item_order = wc_get_order($item->get_order_id());
				$net_qty = $this->wxp_get_item_net_qty($item_order, $item_id, $item_data['quantity']);
				$ordered = (int) $item_data['quantity'];
				$refunded = abs((int) $item_order->get_qty_refunded_for_item($item_id));
				$products[] = array(
					'id' => $item_data['id'],
					'item_id' => $item_data['id'],
					'name' => $item_data['name'],
					'qty' => $net_qty,
					'shipped' => is_array($wxp_shipment) && array_key_exists($item_id, $wxp_shipment) ? $wxp_shipment[$item_id]['item_qty'] : 0,
					'refunded' => ($ordered > 0 && $refunded >= $ordered),
					'order_id' => $item['order_id']
				);
			}
		}

		echo json_encode(array('order_id' => $order_id, 'item_id' => $item_id, 'valid' => $valid, 'products' => $products, 'check' => wp_create_nonce('wxp_check_' . $order_id)));
		exit();
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

		$wxp_shipment = $this->get_wxp_shipment_data($order_id);
		if ($order_id) {
			global $wpdb;
			$shipment_id = $this->get_shipment_id($order_id);


			if (!$shipment_id) {
				$data = array(
					'order_id' => $order_id,
					'shipment_id' => 1,
					'shipment_url' => '',
					'shipment_num' => '',
					'shipment_date' => current_time('timestamp'),
				);
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin table.
				$wpdb->insert($wpdb->prefix . "partial_shipment", $data, array('%d', '%d', '%s', '%s', '%s'));
				$shipment_id = $wpdb->insert_id;
			}

			if ($shipment_id) {

				$shipped_items = array();

				if (isset($_POST['shipped']) && is_array($_POST['shipped'])) {
					$shipped_items = map_deep(wp_unslash($_POST['shipped']), 'sanitize_text_field');
				}

				foreach ($shipped_items as $shipped_item_key => $shipped_item) {
					$item_id = isset($shipped_item['item_id']) ? intval($shipped_item['item_id']) : 0;
					if (! $item_id) {
						continue;
					}

					$type = isset($shipped_item['type']) ? $shipped_item['type'] : 'shipped';
					$qty  = isset($shipped_item['shipped']) ? intval($shipped_item['shipped']) : 0;

					if ('not-shipped' === $type) {
						$qty = 0;
					} else {
						$qty = max(0, $qty);
						// Refund-aware: never store more than the net shippable qty.
						$order_item = is_a($order, 'WC_Order') ? $order->get_item($item_id) : null;
						if ($order_item) {
							$ordered = $order_item->get_quantity();
							$net     = $this->wxp_get_item_net_qty($order, $item_id, $ordered);
							$qty     = min($qty, $net);
						}
					}

					if (array_key_exists($item_id, $wxp_shipment)) {
						// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin table.
						$wpdb->update(
							$wpdb->prefix . "partial_shipment_items",
							array('item_qty' => $qty),
							array('shipment_id' => $shipment_id, 'item_id' => $item_id),
							array('%d'),
							array('%d', '%d')
						);
					} elseif ($qty > 0) {
						$data = array(
							'shipment_id' => $shipment_id,
							'shipment_primary_id' => 1,
							'item_id' => $item_id,
							'item_qty' => $qty,
						);
						// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin table.
						$wpdb->insert($wpdb->prefix . "partial_shipment_items", $data, array('%d', '%d', '%d', '%d'));
					}
				}
			}

			if (is_a($order, 'WC_Order')) {
				// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Custom plugin hook.
				do_action('wxp_order_status', $order_id);
				$order->update_meta_data('_init_wxp_shipment', 1);
				$order->save();
				$order = wc_get_order($order_id);
				$status_key = $order->get_status();
				$status_key = wc_is_order_status('wc-' . $status_key) ? 'wc-' . $status_key : $status_key;
			}
		}

		echo json_encode(array('order_id' => $order_id, 'status' => $status_key));
		exit();
	}

	function get_shipment_id($order_id)
	{
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin table.
		$shipment_id = $wpdb->get_var($wpdb->prepare("SELECT id as ship_id FROM " . $wpdb->prefix . "partial_shipment WHERE order_id=%d", $order_id));
		return $shipment_id;
	}

	function get_wxp_shipment_data($order_id)
	{
		global $wpdb;
		$shipment = array();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin table.
		$shipment_id = $wpdb->get_var($wpdb->prepare("SELECT id as ship_id FROM " . $wpdb->prefix . "partial_shipment WHERE order_id=%d", $order_id));
		if ($shipment_id > 0) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin table.
			$shipment_items = $wpdb->get_results($wpdb->prepare("SELECT * FROM " . $wpdb->prefix . "partial_shipment_items WHERE shipment_id=%d", $shipment_id), ARRAY_A);
			if (is_array($shipment_items) && !empty($shipment_items)) {
				foreach ($shipment_items as $item_key => $item) {
					if (isset($item['item_id'])) {
						$shipment[$item['item_id']] = $item;
					}
				}
			}
		}
		return $shipment;
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

		if (is_a($product, 'WC_Product')) {
			if ($product->is_virtual()) {
				$state   = 'shipped';
				$shipped = $total;
			} else {
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
		$state  = $this->get_item_ship_state($item, $order);
		$labels = $this->wc_partial_labels;

		if ('shipped' === $state['state']) {
			$cls   = 'wxp-shipped';
			$label = $labels['shipped'];
			$count = $state['shipped'];
		} elseif ('partial' === $state['state']) {
			$cls   = 'wxp-partial-shipped';
			$label = $labels['partially-shipped'];
			$count = $state['shipped'];
		} elseif ('refunded' === $state['state']) {
			$cls   = 'wxp-refunded';
			$label = $labels['refunded'];
			$count = $state['shipped'];
		} else {
			$cls   = 'wxp-not-shipped';
			$label = $labels['not-shipped'];
			$count = $state['total'];
		}

		$title = $label . ': ' . $count . '/' . $state['total'];

		return '<a href="javascript:void(0);" class="wxp-top" title="' . esc_attr($title) . '"><span class="' . esc_attr($cls) . ' wxp-ship-status" title="' . esc_attr($label) . '">' . esc_html($label) . ' - ' . esc_html($count) . '</span></a>';
	}

	// Front End Status
	function wxp_order_item_icons($item_id, $item, $order, $bol = false)
	{

		$icon = '';
		$show = true;
		$order_id = is_a($item, 'WC_Order_Item_Product') ? $item->get_order_id() : 0;
		if (is_a($order, 'WC_Order')) {
			$init = $order->get_meta('_init_wxp_shipment');
			if (isset($this->wc_partial_shipment_settings['partially_hide_status']) && $this->wc_partial_shipment_settings['partially_hide_status'] == 'yes' && $init != '1') {
				$show = false;
			}
			if ($show) {
				// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- HTML generated internally and sanitized.
				$icon = $this->get_item_status_icon_html($item, $order);
			}
		}

		if (is_view_order_page()) {
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- HTML generated internally and sanitized.
			echo '<div class="wxp-order-item-ship-status">' . $icon . '</div>';
		}
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
		$order_id = $order->get_id();
		WC()->payment_gateways();
		WC()->shipping();
		$emails = WC()->mailer()->get_emails();
		$emails['WC_Email_Customer_Partial_shipment']->trigger($order_id);
		$order->add_order_note(__('Partial order details manually sent to customer.', 'wc-partial-shipment'), false, true);
		add_filter('redirect_post_location', array($this, 'set_email_sent_message'));
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

		$total_count = 0;
		$shipped_count = 0;
		$order = wc_get_order($order_id);
		if (is_a($order, 'WC_Order')) {

			$wxp_shipment = $this->get_wxp_shipment_data($order_id);
			$items = $order->get_items();
			if (is_array($items) && !empty($items)) {
				foreach ($items as $item) {
					$product = $item->get_product();
					if (is_a($product, 'WC_Product') && !$product->is_virtual()) {
						$total_count = $total_count + $this->wxp_get_item_net_qty($order, $item->get_id(), $item->get_quantity());
					}
				}
			}
			if (is_array($wxp_shipment) && !empty($wxp_shipment)) {
				foreach ($wxp_shipment as $shipped_item) {
					if (isset($shipped_item['item_qty'])) {
						$shipped_count = $shipped_count + $shipped_item['item_qty'];
					}
				}
			}

			if ($total_count > 0 && $shipped_count == $total_count && wc_is_order_status('wc-completed')) {
				if ($this->wc_partial_shipment_settings['partially_auto_complete'] == 'yes') {
					$order->update_status('completed', __('Order Completed by Woocommerce Partial Shipment.', 'wc-partial-shipment'));
					$order->save();
				}
			} elseif ($total_count > 0 && $shipped_count < 1 && wc_is_order_status('wc-processing')) {
				$order->update_status('processing', __('Order Processed by Woocommerce Partial Shipment.', 'wc-partial-shipment'));
				$order->save();
			} elseif ($total_count > 0 && $shipped_count > 0 && $shipped_count < $total_count && wc_is_order_status('wc-partial-shipped')) {
				if ($this->wc_partial_shipment_settings['partially_shipped_status'] == 'yes') {
					$order->update_status('partial-shipped', __('Order Partially Shipped by Woocommerce Partial Shipment.', 'wc-partial-shipment'));
					$order->save();
				}
			}
		}
	}

	function wxp_order_status_switch($order_id)
	{
		$order = wc_get_order($order_id);
		if (is_a($order, 'WC_Order')) {
			global $wpdb;
			$shipment_id = $this->get_shipment_id($order_id);
			if (!$shipment_id) {
				$data = array(
					'order_id' => $order_id,
					'shipment_id' => 1,
					'shipment_url' => '',
					'shipment_num' => '',
					'shipment_date' => current_time('timestamp'),
				);
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin table.
				$wpdb->insert($wpdb->prefix . "partial_shipment", $data, array('%d', '%d', '%s', '%s', '%s'));
				$shipment_id = $wpdb->insert_id;
			}

			if ($shipment_id) {
				$wxp_shipment = $this->get_wxp_shipment_data($order_id);
				$items = $order->get_items();
				if (is_array($items) && !empty($items)) {
				foreach ($items as $item_key => $item) {
					$item_id  = $item->get_id();
					$item_qty = $item->get_quantity();
					// Refund-aware: never mark more as shipped than the net qty
					// still owed (ordered qty minus refunded qty). WC returns the
					// refunded amount as a negative number.
					$refunded = $order->get_qty_refunded_for_item( $item_id );
					$net_qty  = max( 0, (int) $item_qty + (int) $refunded );
					if (array_key_exists($item_id, $wxp_shipment)) {
						// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin table.
						$wpdb->update(
							$wpdb->prefix . "partial_shipment_items",
							array('item_qty' => $net_qty),
							array('shipment_id' => $shipment_id, 'item_id' => $item_id),
							array('%d'),
							array('%d', '%d')
						);
					} elseif ($net_qty > 0) {
						$data = array(
							'shipment_id' => $shipment_id,
							'shipment_primary_id' => 1,
							'item_id' => $item_id,
							'item_qty' => $net_qty,
						);
							// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin table.
							$wpdb->insert($wpdb->prefix . "partial_shipment_items", $data, array('%d', '%d', '%d', '%d'));
						}
					}
					$order->update_meta_data('_init_wxp_shipment', 1);
					$order->save();
				}
			}
		}
	}

	/**
	 * Backfill shipment records for past orders of one or more chosen statuses
	 * that have none. Intended for orders that were already shipped (e.g.
	 * Completed) before this plugin was installed and therefore never fired
	 * the woocommerce_order_status_completed transition hook.
	 *
	 * Only processes orders lacking a partial_shipment row, so it is safe
	 * to run repeatedly and never overwrites a merchant's manual edits.
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

		$processed = 0;
		$skipped   = 0;

		foreach ($statuses as $status) {
			$order_ids = wc_get_orders(array(
				'status' => $status,
				'type'   => 'shop_order',
				'limit'  => -1,
				'return' => 'ids',
			));

			if (is_array($order_ids) && ! empty($order_ids)) {
				foreach ($order_ids as $order_id) {
					if ($this->get_shipment_id($order_id)) {
						$skipped++;
						continue;
					}
					$this->wxp_order_status_switch($order_id);
					$processed++;
				}
			}
		}

		set_transient('wxp_backfill_notice', array(
			'processed'    => $processed,
			'skipped'      => $skipped,
			'status_label' => implode(', ', $status_list),
		), 30);

		$redirect = admin_url('admin.php?page=wc-settings&tab=wxp_partial_shipping_settings');
		wp_safe_redirect($redirect);
		exit();
	}

	/**
	 * Show the result of the backfill run as an admin notice.
	 */
	function wxp_backfill_admin_notice()
	{
		$notice = get_transient('wxp_backfill_notice');
		if (empty($notice) || ! is_array($notice)) {
			return;
		}
		delete_transient('wxp_backfill_notice');

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
}
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
function wxp_partial_shipment_init()
{
	return WXP_Partial_Shipment::instance();
}

if (function_exists('is_multisite') && is_multisite()) {
	if (!function_exists('is_plugin_active_for_network')) {
		require_once(ABSPATH . '/wp-admin/includes/plugin.php');
	}
	if (is_plugin_active_for_network('woocommerce/woocommerce.php') && !is_plugin_active_for_network('wc-partial-shipment-pro/woocommerce-partial-shipment-pro.php')) {
		wxp_partial_shipment_init();
	}
} else {
	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Custom plugin hook.
	if (!in_array('wc-partial-shipment-pro/woocommerce-partial-shipment-pro.php', apply_filters('active_plugins', get_option('active_plugins')))) {
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound	
		if (in_array('woocommerce/woocommerce.php', apply_filters('active_plugins', get_option('active_plugins')))) {
			wxp_partial_shipment_init();
		}
	}
}
