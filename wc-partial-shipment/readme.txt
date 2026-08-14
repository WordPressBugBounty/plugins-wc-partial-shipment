=== Partial Shipment for WooCommerce ===

Contributors: wpexpertshub, jodhavishalsingh
Tags: Partial Shipment for WooCommerce,WooCommerce Partial Shipment,Partial Shipment,woocommerce Shipment,WooCommerce Partial Shipping
License: GPLv3
License URI: http://www.gnu.org/licenses/gpl-3.0.html
Donate link: https://www.paypal.com/cgi-bin/webscr?cmd=_xclick&business=jodhavishalsingh@gmail.com&item_name=Donation For Plugin

Requires at least: 6.9
Tested up to: 7.0
Requires PHP: 7.4
WC requires at least: 8.0
WC tested up to: 10.8.1
Stable tag: 3.6

Ship WooCommerce orders in parts. Track shipped and remaining item quantities (refund-aware), show status badges, and backfill existing orders.

== Description ==

Partial Shipment for WooCommerce makes it easy to manage orders that need to be shipped in multiple parts. Store managers can ship specific products or quantities directly from the WooCommerce order edit screen while keeping the original order intact.
The plugin keeps track of shipped and remaining quantities, automatically accounts for refunded items, and provides clear shipment information to both store managers and customers. Customers can view which items have been shipped directly from their order details page.
With optional partial shipment status management and a simple backfill tool for existing orders, the plugin provides an easy way to manage partial fulfillment in WooCommerce.

= Basic Features =

🔹 Partially ship a WooCommerce order, per item quantity, directly from the order edit screen.
🔹 Optional custom "Partially Shipped" order status.
🔹 Refund-aware quantities — fully-refunded items are marked "Refunded" and excluded from shippable counts, and the "Not Shipped" badge shows the quantity still to ship.
🔹 Backfill existing orders of any status with shipment records in one click (refund-aware, skips orders that already have records).
🔹 Status badges on the admin order-list popup and the customer's View Order page.
🔹 Customer can see the shipped items on the order detail page.
🔹 Translation ready — ships a .pot and loads the text domain.

➡ <strong>[GET PREMIUM VERSION NOW!](https://wpexpertshub.com/plugins/advance-partial-shipment-for-woocommerce/)</strong>

= Premium Features =

== Key Features ==

&#9989; Partially ship WooCommerce orders with specific products or quantities.
&#9989; Add Tracking Number, Tracking URL, and Shipping Provider to each shipment.
&#9989; Create multiple partial or full shipments for a single order.
&#9989; Manage shipments through Ready, Picked, Dispatched, and Delivered statuses.
&#9989; Automatically update the order to "Partially Shipped" and complete it when fully shipped.
&#9989; Send automatic shipment notification emails with tracking information.
&#9989; Choose when to send shipment emails — when created or when marked as Picked.
&#9989; Display shipment details, tracking information, and shipment timeline to customers.
&#9989; Add Estimated Delivery Date, customer notes, and internal shipment notes.
&#9989; Print Packing Slips and Delivery Notes for individual shipments.
&#9989; Provide a public shipment tracking page using a shortcode.
&#9989; Add custom Shipping Providers and tracking URLs.
&#9989; Retrieve and update shipment data using the REST API.
&#9989; Send shipment events to external systems using Webhooks.
&#9989; Track fulfillment progress with Fulfillment Reports and CSV export.
&#9989; Automatically account for refunded quantities when calculating remaining items to ship.
&#9989; Bulk create shipments and backfill existing WooCommerce orders.
&#9989; Fast and dedicated support.

[youtube https://youtu.be/Cy2B6_fUiG8]

== Installation ==
1. Simply install and activate the plugin.
2. Now you can see Shipment button and icon on order edit page.
3. you can set item shipment there.
4. Partial Shipment Settings is under <strong>WooCommerce >> Settings >> Partial Shipment tab.</strong>

== Frequently Asked Questions ==

= Where can I get support or talk to other users ? =

If you get stuck, you can ask for help in the [Plugin Forum](https://wordpress.org/support/plugin/wc-partial-shipment/).

= Where can I get support for premium version ? =

You can write us directly for premium version help or [Contact us](https://wpexpertshub.com/contact-us/), please do not post on wordpress support forum for premium version help.

== Screenshots ==

1. Admin Orders List Page
2. Shipment Details Popup on the Order Page
3. Shipment Status on the Order Page
4. Refunded Items in the Shipment Popup
5. Single Item Shipment & Actions
6. Customer Orders List Page
7. Shipment Status on the Customer Order Details Page
8. Partial Shipment Plugin Settings

== Changelog ==

= 3.6 - 2026-08-14 =
* New - Refund-aware shipment quantities and labels: shipped/available counts use the net (post-refund) quantity, fully-refunded items show a "Refunded" label instead of "Not Shipped: 0", and the admin/order "Not Shipped" count now reflects the quantity still to ship.
* New - Backfill tool: create shipment records (items marked as shipped) for existing orders of one or more selected statuses (defaults to Completed). Refund-aware and skips orders that already have records.
* New - The backfill status selector uses WooCommerce's native (selectWoo) multi-select styling.
* Tweak - Regenerated languages/wc-partial-shipment.pot; the "wc-partial-shipment" text domain is declared in the plugin header, so WordPress.org serves translations automatically.
* Tweak - Replaced the jQuery fancybox modal with a dependency-free native <dialog> (vanilla JS + theme-proof CSS).
* Fix - "Unset Shipped" bulk/single action now correctly resets the item quantity to 0.
* Tweak - Improved status badge styling (pill badges, responsive width, box-sizing/font normalization).
* Tweak - Code cleanup: WpHub_Partial_Shipment_Sql renamed to Wxp_Partial_Shipment_Sql, current_time() fix, removed duplicate trunk/ and dead lang/ directories.

= 3.5 - 03/06/2026 =
* Update - security enhancements.

= 3.4 - 20/09/2025 =
* Update - Compatibility checked.

= 3.3 - 21/06/2025 =
* Fix - Escaped MySQL queries for improved security.
* Fix - Sanitized POST variables properly.
* Tweak - Compatibility check and Tested with latest version WC 9.9

= 3.2 - 06/09/2024 =
* Fix - Mark all ordered items as shipped when the order status changes to Completed.

= 3.1 - 26/08/2024 =
* Update - updated to WooCommerce HPOS compatibility.

= 3.0 - 23/08/2023 =
* Tweak - Compatibility checked.

= 2.9 - 29/07/2022 =
* Tweak - Compatibility checked.

= 2.8 - 22/05/2022 =
* Tweak - minor tweaks.

= 2.7 - 06/01/2022 =
* Fix - Virtual product error is fixed.
* Fix - Translation string fixed and added in pot file.

= 2.6 - 18/02/2021 =
* Tweak - Check if product or order item is null.

= 2.5 - 18/02/2021 =
* Fix - Fixed error during plugin activation.

= 2.4 - 13/02/2021 =
* Fix - Partially Shipping popup filled with max quantity ordered.
* Fix - Partially Shipping labels hidden for virtual products ordered.
* Fix - Filter to change the shipped label.
* Tweak - compatibility checked with latest version of wc/wp.

= 2.3 - 31/08/2020 =
* Fix - Quantity update issue fixed during manually order item editing.
* Fix - Item status fixed during manually order item editing.
* Tweak - compatibility issue fixed.

= 2.2 - 06/06/2020 =
* Fix - Extra product options and custom product field options compatibility issue fixed.
* Tweak - minor tweaks.

= 2.1 - 19/05/2020 =
* Fix - compatibility issue fixed for ordered item check.
* Tweak - Improved stored data.

= 2.0 - 13/05/2020 =
* Fix - compatibility issue fixed for latest WooCommerce version and php 7.0 and above.
* Fix - Auto Switch order status based on shipment.
* Tweak - Auto Ship all products when order marked as completed.
* Tweak - Setting option to hide label on my order page until items are shipped.

= 1.9 - 21/11/2019 =
* Feature - Multilingual ready / Translation ready.
* Fix - Order status changed to completed If all the items are marked as shipped.
* Tweak - Tweaks and tested up to latest version.

= 1.8 - 03/10/2019 =
* Fix - Tweaks and tested up to latest requirement.

= 1.7 - 05/03/2019 =
* Fix - Email Templates overriding fixed from theme.

= 1.6 - 01/12/2018 =
* Feature - Partial Shipment Email action added on order edit screen so store manager can trigger manual email when order is shipped.
* Feature - Status column added in order list popup.
* Feature - Popup settings added in partial shipment setting tab.

= 1.5 - 22/11/2018 =
* Feature - Setting added to hide status label just after order generated.

= 1.4 - 30/08/2018 =
* Bug Fix.

= 1.3 - 30/08/2018 =
* Bug Fix.

= 1.2 - 25/08/2018 =
* Bug Fix.
* UI Update

= 1.1 - 25/07/2018 =
* Bug Fix.

= 1.0 - 02/05/2018 =
* Initial release.