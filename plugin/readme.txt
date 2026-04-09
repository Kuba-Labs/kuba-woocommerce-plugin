=== Kuba Labs - WhatsApp Marketing for WooCommerce ===
Contributors: kubalabs
Tags: whatsapp, woocommerce, marketing, automation, abandoned cart
Requires at least: 6.8
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Connect your WooCommerce store to Kuba Labs for automated WhatsApp marketing, order notifications, and abandoned cart recovery.

== Description ==

Kuba Labs connects your WooCommerce store to the WhatsApp Business Platform, enabling automated customer communication powered by AI.

= Features =

* **Order notifications** — Automatically send order confirmations, shipping updates, and delivery notifications via WhatsApp.
* **Abandoned cart recovery** — Recover lost sales by sending WhatsApp reminders to customers who didn't complete checkout.
* **AI-powered customer support** — Let AI handle common questions, recommend products, and escalate to humans when needed.
* **Visual automation builder** — Build multi-step WhatsApp flows with a drag-and-drop editor. No code required.
* **Checkout enhancements** — Country calling code selector and WhatsApp opt-in checkbox at checkout, compatible with both Classic and Blocks checkout.
* **WhatsApp chat widget** — Add a floating WhatsApp button to your store automatically.
* **Revenue attribution** — See exactly how much revenue your WhatsApp messages generate.

= How it works =

1. Install and activate this plugin.
2. Connect to your Kuba Labs account in WooCommerce > Settings > Kuba Labs.
3. Your store events (orders, checkouts, products) are securely sent to Kuba Labs.
4. Build automated WhatsApp flows in the Kuba Labs dashboard.

= What data is sent? =

This plugin sends WooCommerce event data (orders, products, checkout status) to Kuba Labs servers at api.kubalabs.com over HTTPS. All data is signed with HMAC-SHA256 for authenticity. No customer data is stored by the plugin itself — it only forwards events to your Kuba Labs account.

For more information, see our [Privacy Policy](https://kubalabs.com/privacy) and [Terms of Service](https://kubalabs.com/terms).

= Requirements =

* A [Kuba Labs](https://kubalabs.com) account
* WooCommerce 8.0 or later
* PHP 7.4 or later

== Installation ==

1. Upload the plugin files to `/wp-content/plugins/kuba-labs/` or install through the WordPress plugin screen.
2. Activate the plugin.
3. Go to **WooCommerce > Settings > Kuba Labs**.
4. Click **Connect to Kuba Labs** and follow the authorization flow.
5. Once connected, events are sent automatically. Configure your WhatsApp flows in the Kuba Labs dashboard.

== Frequently Asked Questions ==

= Do I need a Kuba Labs account? =

Yes. This plugin is a connector between your WooCommerce store and the Kuba Labs platform. Sign up at [kubalabs.com](https://kubalabs.com).

= Does this plugin modify my checkout? =

Optionally, yes. When enabled in WooCommerce > Settings > Kuba Labs, the plugin adds a country calling code selector and a WhatsApp consent checkbox to your checkout. Both use the official WooCommerce Additional Checkout Fields API and work with Classic and Blocks checkout. These features can be disabled individually in the settings.

= Is the WhatsApp widget included? =

Yes. Once connected, a customizable WhatsApp chat button is automatically added to your storefront. You can customize its appearance in the Kuba Labs dashboard.

= What happens if I deactivate the plugin? =

Events stop being sent to Kuba Labs. Your data in Kuba Labs is preserved. Reactivating the plugin resumes event delivery.

= What happens if I delete the plugin? =

All plugin data is removed from your WordPress installation, including the connection credentials and API keys. You would need to reconnect after reinstalling.

= Is it compatible with HPOS (High-Performance Order Storage)? =

Yes. The plugin fully supports WooCommerce HPOS and uses only WooCommerce CRUD methods for order data access.

= Which shipping/tracking plugins are supported? =

The plugin sends all order metadata to Kuba Labs, which supports tracking data from WooCommerce Shipment Tracking, AfterShip, and other plugins that store tracking info in order meta. New tracking plugins can be supported server-side without a plugin update.

== Screenshots ==

1. Settings page — connected state showing store ID and connection status.
2. Settings page — disconnected state with one-click connect button.
3. WhatsApp widget on your storefront (customizable in Kuba Labs dashboard).

== Changelog ==

= 1.0.0 =
* Initial release.
* WooCommerce event forwarding (orders, products, checkouts).
* OAuth-style connection flow.
* HMAC-SHA256 signed webhook delivery.
* Abandoned cart detection via draft order sweep.
* WhatsApp chat widget injection.
* Checkout enhancements: country calling code selector and WhatsApp consent checkbox.
* Multi-product cart URL support (?kuba_cart=).
* Full HPOS and Blocks compatibility.
* Action Scheduler based reliable delivery with automatic retries.

== Upgrade Notice ==

= 1.0.0 =
Initial release.
