=== Kuba Labs for WooCommerce ===
Contributors: kubalabs
Tags: woocommerce, marketing, automation, abandoned cart, messaging
Requires at least: 6.8
Tested up to: 6.9
Requires PHP: 8.0
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Connect your WooCommerce store to Kuba Labs for automated messaging, order notifications, and abandoned cart recovery.

== Description ==

Kuba Labs connects your WooCommerce store to the WhatsApp Business Platform via the Kuba Labs service, enabling automated customer communication powered by AI.

= Features =

* **Order notifications** — Automatically send order confirmations, shipping updates, and delivery notifications.
* **Abandoned cart recovery** — Recover lost sales by sending reminders to customers who didn't complete checkout.
* **AI-powered customer support** — Let AI handle common questions, recommend products, and escalate to humans when needed.
* **Visual automation builder** — Build multi-step messaging flows with a drag-and-drop editor. No code required.
* **Checkout enhancements** — Country calling code selector and messaging opt-in checkbox at checkout, compatible with both Classic and Blocks checkout.
* **Chat widget** — Add a floating chat button to your store automatically.
* **Revenue attribution** — See exactly how much revenue your messaging generates.

= How it works =

1. Install and activate this plugin.
2. Connect to your Kuba Labs account in WooCommerce > Settings > Kuba Labs.
3. Your store events (orders, checkouts, products) are securely sent to Kuba Labs.
4. Build automated messaging flows in the Kuba Labs dashboard.

= Requirements =

* A [Kuba Labs](https://kubalabs.com) account
* WooCommerce 8.0 or later
* PHP 8.0 or later

== Installation ==

1. Upload the plugin files to `/wp-content/plugins/kuba-labs/` or install through the WordPress plugin screen.
2. Activate the plugin.
3. Go to **WooCommerce > Settings > Kuba Labs**.
4. Click **Connect to Kuba Labs** and follow the authorization flow.
5. Once connected, events are sent automatically. Configure your messaging flows in the Kuba Labs dashboard.

== External Services ==

This plugin relies on the Kuba Labs service to deliver messaging, store automation flows, and render the chat widget. No external calls are made until you explicitly click **Connect to Kuba Labs** in the plugin settings.

**1. Kuba Labs API — api.kubalabs.com**

* **Purpose:** Forward WooCommerce events (order created, order status changed, product updated, checkout activity, abandoned cart) to your Kuba Labs account for automation and messaging.
* **Data transmitted:** Order data (billing name, email, phone, shipping address, line items, totals, statuses), product data (name, price, SKU, image, categories), checkout capture data (phone, email, country), and store metadata (store URL, store ID, WooCommerce REST API credentials). Sensitive payment meta (Stripe, PayPal, transaction tokens) is filtered out before transmission.
* **When:** On every relevant WooCommerce hook while the plugin is connected. Requests are signed with HMAC-SHA256 using a per-store webhook secret.
* **Terms of Service:** [https://kubalabs.com/terms](https://kubalabs.com/terms)
* **Privacy Policy:** [https://kubalabs.com/privacy](https://kubalabs.com/privacy)

**2. Kuba Labs Widget — app.kubalabs.com/widget.js**

* **Purpose:** Load the chat widget script on your storefront so visitors can start a conversation.
* **Data transmitted:** Standard browser request metadata (IP address, user agent, referring URL) when a visitor's browser fetches the widget script and communicates with the Kuba Labs widget backend. The widget is only loaded when a connection is active and the widget is enabled in settings.
* **Terms of Service:** [https://kubalabs.com/terms](https://kubalabs.com/terms)
* **Privacy Policy:** [https://kubalabs.com/privacy](https://kubalabs.com/privacy)

If you do not wish to send data externally, do not connect the plugin. A deactivated or unconnected plugin makes no external requests.

== Privacy Policy ==

This plugin is a connector to the Kuba Labs service (https://kubalabs.com). The plugin itself does not track or analyze visitors on your site. When connected, it forwards WooCommerce event data to Kuba Labs as described in the **External Services** section above.

**Local data stored by the plugin:**

* Connection credentials (store ID, webhook secret, widget key) in the WordPress options table.
* A custom table (`{prefix}kuba_labs_consents`) that records messaging opt-in state by session token and phone number. This table is created on activation and removed on uninstall.
* Short-lived capture records (merged into `wp_options` with the `_kuba_capture_` prefix) used to detect classic-checkout abandonments. These are removed on uninstall.

**User consent:**

* Data transmission to Kuba Labs only begins after a merchant admin explicitly connects the store via OAuth.
* If the messaging consent checkbox is enabled at checkout, each customer gives per-order opt-in via a WooCommerce Additional Checkout Fields API checkbox.

**Data removal:**

* Deactivating the plugin stops event forwarding and notifies the Kuba Labs backend to mark the store inactive.
* Deleting the plugin triggers `uninstall.php`, which removes all plugin options, the consent table, capture records, and the WooCommerce REST API keys the plugin generated.

For how Kuba Labs processes the data it receives, see the [Kuba Labs Privacy Policy](https://kubalabs.com/privacy).

== Frequently Asked Questions ==

= Do I need a Kuba Labs account? =

Yes. This plugin is a connector between your WooCommerce store and the Kuba Labs platform. Sign up at [kubalabs.com](https://kubalabs.com).

= Does this plugin modify my checkout? =

Optionally, yes. When enabled in WooCommerce > Settings > Kuba Labs, the plugin adds a country calling code selector and a messaging consent checkbox to your checkout. Both use the official WooCommerce Additional Checkout Fields API and work with Classic and Blocks checkout. These features can be disabled individually in the settings.

= Is the chat widget included? =

Yes. Once connected, a customizable chat button is automatically added to your storefront. You can customize its appearance in the Kuba Labs dashboard.

= What happens if I deactivate the plugin? =

Events stop being sent to Kuba Labs. Your data in Kuba Labs is preserved. Reactivating the plugin resumes event delivery.

= What happens if I delete the plugin? =

All plugin data is removed from your WordPress installation, including the connection credentials, API keys, and the consent table. You would need to reconnect after reinstalling.

= Is it compatible with HPOS (High-Performance Order Storage)? =

Yes. The plugin fully supports WooCommerce HPOS and uses only WooCommerce CRUD methods for order data access.

= Which shipping/tracking plugins are supported? =

The plugin sends all order metadata to Kuba Labs, which supports tracking data from WooCommerce Shipment Tracking, AfterShip, and other plugins that store tracking info in order meta. New tracking plugins can be supported server-side without a plugin update.

== Screenshots ==

1. Settings page — connected state showing store ID and connection status.
2. Settings page — disconnected state with one-click connect button.
3. Chat widget on your storefront (customizable in Kuba Labs dashboard).

== Changelog ==

= 1.0.0 =
* Initial release.
* WooCommerce event forwarding (orders, products, checkouts).
* OAuth-style connection flow.
* HMAC-SHA256 signed webhook delivery.
* Abandoned cart detection via draft order sweep.
* Chat widget injection.
* Checkout enhancements: country calling code selector and messaging consent checkbox.
* Multi-product cart URL support (?kuba_cart=).
* Full HPOS and Blocks compatibility.
* Action Scheduler based reliable delivery with automatic retries.

== Upgrade Notice ==

= 1.0.0 =
Initial release.
