<?php

/**
 * Email Order Items (plain)
 *
 * This template can be overridden by copying it to yourtheme/woocommerce/emails/plain/email-order-items.php.
 *
 * HOWEVER, on occasion WooCommerce will need to update template files and you
 * (the theme developer) will need to copy the new files to your theme to
 * maintain compatibility. We try to do this as little as possible, but it does
 * happen. When this occurs the version of the template file will be bumped and
 * the readme will list any important changes.
 *
 * @see 	    https://docs.woocommerce.com/document/template-structure/
 * @author 		WooThemes
 * @package 	WooCommerce/Templates/Emails/Plain
 * @version 3.6.0
 */

if (! defined('ABSPATH')) {
	exit; // Exit if accessed directly
}
// phpcs:ignoreFile -- Ignore all variable prefix warnings in this file.
foreach ($items as $item_id => $item) :
	if (apply_filters('woocommerce_order_item_visible', true, $item)) {
		$product = $item->get_product();
		echo esc_html(apply_filters('woocommerce_order_item_name', $item->get_name(), $item, false));
		if ($show_sku && $product->get_sku()) {
			echo ' (#' . esc_html($product->get_sku()) . ')';
		}
		echo ' X ' . esc_html(apply_filters('woocommerce_email_order_item_quantity', $item->get_quantity(), $item));
		echo ' - ' . esc_html(wxp_partial_shipment_init()->get_item_status($item_id, $item, $order));
		echo ' = ' . esc_html($order->get_formatted_line_subtotal($item)) . "\n";

		// allow other plugins to add additional product information here
		do_action('woocommerce_order_item_meta_start', $item_id, $item, $order, $plain_text);
		echo esc_html(wp_strip_all_tags(wc_display_item_meta($item, array(
			'before'    => "\n- ",
			'separator' => "\n- ",
			'after'     => "",
			'echo'      => false,
			'autop'     => false,
		))));

		// allow other plugins to add additional product information here
		do_action('woocommerce_order_item_meta_end', $item_id, $item, $order, $plain_text);
	}
	// Note
	if ($show_purchase_note && is_object($product) && ($purchase_note = $product->get_purchase_note())) {
		echo "\n" . do_shortcode(wp_kses_post($purchase_note));
	}
	echo "\n\n";
endforeach;
