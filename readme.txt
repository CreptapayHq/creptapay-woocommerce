=== CreptaPay for WooCommerce ===
Contributors: creptapay
Tags: woocommerce, crypto, stablecoin, usdc, usdt
Requires at least: 6.0
Tested up to: 6.6
Requires PHP: 7.4
Stable tag: 0.2.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Accept stablecoins (USDC and USDT) in WooCommerce. Orders are confirmed automatically by a signed webhook.

== Description ==

CreptaPay adds a "Pay with stablecoins" option to your WooCommerce checkout. Customers are sent to the secure CreptaPay checkout, pay with USDC or USDT from any wallet on the network of their choice, and come back to your order confirmation page. Your order is marked paid as soon as CreptaPay confirms the payment.

* Sandbox and live keys, with a single checkbox to switch.
* The webhook is registered with CreptaPay automatically when you save your keys.
* Webhooks are verified with your secret key (HMAC-SHA256) and replay-protected.
* Every payment is re-checked with CreptaPay before an order is completed, including amount and currency.
* Works with the classic checkout and the Checkout block. Compatible with HPOS.
* Store currency must be one CreptaPay supports (USD, EUR and NGN at the time of writing). The list is fetched from CreptaPay hourly, so newly supported currencies work without updating the plugin.

== Installation ==

1. Upload the `creptapay-woocommerce` folder to `/wp-content/plugins/`, or upload the zip under Plugins → Add New → Upload Plugin.
2. Activate the plugin.
3. Go to WooCommerce → Settings → Payments → CreptaPay.
4. Paste your sandbox keys (and live keys when ready) from your CreptaPay dashboard → Developers → API keys.
5. Tick "Enable CreptaPay" and save. The settings page shows whether the webhook was registered and reachable.

Your site must be reachable over https for CreptaPay to deliver webhooks. On a local site, orders are still checked when the customer returns to the thank-you page.

== Frequently Asked Questions ==

= Which order statuses are used? =

* Paid or overpaid: the order is completed through WooCommerce's normal "payment complete" flow (Processing, or Completed for virtual/downloadable orders).
* Partial payment: On hold, with a note of how much arrived.
* Expired without full payment: Failed, so the customer can try again.

= My webhook shows as not reachable =

Make sure the site is public, uses https, and that no security plugin or firewall blocks POST requests to `/wc-api/creptapay/`.

== Changelog ==

= 0.2.0 =
* Supported store currencies now come from CreptaPay instead of a list baked into the plugin, so a newly supported currency works without an update.
* Added Nigerian naira (NGN) to the currencies shipped as a fallback.

= 0.1.0 =
* First release.
