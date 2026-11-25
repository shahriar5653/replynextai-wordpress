=== ReplyNext AI – AI Chat Assistant for WooCommerce ===
Contributors: replynextai
Tags: live chat, ai chatbot, customer support, woocommerce, whatsapp
Requires at least: 6.0
Tested up to: 7.1.5
Requires PHP: 7.4
Stable tag: 1.4.5
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

AI-powered WhatsApp and website chat for WooCommerce. Automate customer support, answer product questions, and generate more sales with ReplyNext AI.

WooCommerce is optional. ReplyNext AI chat, the storefront widget, and the
WhatsApp, Messenger, and direct-call contact features work without WooCommerce.
WooCommerce is needed for product discovery, order-status lookup, and supported
chat checkout.

== Source code ==

The complete plugin source is publicly available at:
https://github.com/shahriar5653/replynextai-wordpress

The human-readable source for the storefront contact-widget script is
`assets/frontend.source.js`. The minified runtime file loaded by WordPress is
`assets/frontend.js`; it is distributed as-is and no build or minification
pipeline is required to install or use the plugin.

== Description ==

ReplyNext AI Chat & WhatsApp helps website owners start conversations and
capture sales opportunities with two independent modules.

= Contact Channels =

Contact Channels provides WhatsApp, Messenger, and phone contact buttons. The
WhatsApp channel opens a standard `wa.me` conversation and does not require a
WhatsApp API, ReplyNext account, or paid plan.

= ReplyNext AI Website Chat =

Existing ReplyNext clients can connect the AI Website Chat module configured in
their client portal by entering a WordPress connection token. The plugin
automatically creates the website authorization needed for the connected site.

Features:

* Guided ReplyNext dashboard
* WhatsApp, Messenger, and direct-call contact buttons
* AI Website Chat connection
* Live visual previews
* Site-wide display or shortcodes
* Page include/exclude rules
* Mobile and desktop visibility
* Custom WhatsApp label, message, color, position, and delay
* Optional logged-in WordPress user identity sharing
* WooCommerce catalog synchronization in 100-product batches, with live progress and a final count on the plugin page
* Live product and variation price/availability checks
* Private order-status lookup using order reference and matching billing phone
* Optional chat checkout using the store's required customer fields and live quote
* Delivery and payment choices calculated for the current cart and destination
* Confirmed cash-on-delivery orders when enabled and supported by the store
* Optional order-specific links for payment through WooCommerce, with separate consent to offer links alongside cash on delivery
* Payment and order status updates for ReplyNext chat orders

== External services ==

This plugin connects to the ReplyNext AI service at https://replynextai.com when
AI Website Chat, connection status, or WooCommerce catalog synchronization is
enabled. Product names, prices, descriptions, categories, URLs, and image URLs
may be transmitted for catalog synchronization. If the optional logged-in user
identity setting is enabled, the logged-in WordPress user's name and email may
be sent to ReplyNext AI with chat requests.

When a merchant enables WooCommerce order support, ReplyNext AI can send signed
requests to this site's plugin endpoints to check supported checkout fields,
calculate destination-specific delivery and payment options and a live quote,
and—after explicit customer confirmation—create a
WooCommerce order when the store's checkout configuration supports it. If the
merchant separately enables chat payment links, a confirmed online order remains
unpaid until completed on the store's WooCommerce order-payment page. For ReplyNext
chat orders, the plugin sends the order reference and updated status to ReplyNext
for delivery back to the originating conversation. The order's customer fields
are saved in the store's WooCommerce order records. For limited order-status lookup, the order reference and billing
phone are checked against the store's order data; only a matched order's status
is returned. Payment credentials and card data are not collected by this
feature. Review the [ReplyNext AI privacy
policy](https://replynextai.com/privacy-policy) before enabling these features.

The contact buttons open the configured WhatsApp, Messenger, or telephone URL
in the visitor's browser or device. Those services may process data under their
own privacy policies; this plugin does not request their APIs or permissions.

== Installation ==

1. Upload `replynextai-chat.zip` in Plugins → Add New → Upload Plugin.
2. Activate ReplyNext AI Chat & WhatsApp.
3. Open the new ReplyNext AI menu.
4. Configure Contact Channels, AI Website Chat, or both.
5. Test the website in a private browser window.

== Shortcodes ==

AI Website Chat:

`[replynextai_chat]`

Contact Channels:

`[replynextai_whatsapp]`

`[replynextai_whatsapp number="8801XXXXXXXXX" message="Hello"]`

== Frequently Asked Questions ==

= Do Contact Channels use the WhatsApp API? =

No. It opens a direct `wa.me` link using the configured phone number.

= Do I need a ReplyNext account? =

Not for Contact Channels. A ReplyNext account is required for AI Website Chat.

= How does AI Website Chat connect? =

Create a WordPress Plugin Connection in the ReplyNext client portal, enter its
token in the plugin, and use Test Connection. The plugin automatically prepares
the secure website chat authorization for the connected site.

= Can I control where the buttons appear? =

Yes. Display Rules supports selected page IDs, excluded page IDs, and separate
mobile/desktop visibility.

= Which WooCommerce checkouts can run in chat? =

Chat checkout reads the store's supported classic checkout fields and the live
rates and eligible payments for the customer's cart and destination. Built-in
flat rate, free shipping and classic local pickup can be offered for a single
shipping package. A customer can select an available rate before reviewing the
total. Site payments are completed on WooCommerce's own order-payment page.
Checkout Blocks, unsupported mandatory fields, and unsupported shipping
extensions use the store's native checkout. Enable the desired payment-link
options under ReplyNext AI > WooCommerce.

== Changelog ==

= 1.4.5 =
* Verify published products, available variations, and current list and sale prices in the WooCommerce store currency.
* Provide the public product description in live verification, and queue discovery sync for newly published products.

= 1.4.4 =
* Show progress, the completed product count, and errors in the WooCommerce sync card without reloading the page.
* Prevent a failed discovery batch from finalizing an incomplete catalog.

= 1.4.3 =
* Read per-destination delivery choices and eligible payment methods from WooCommerce.
* Revalidate the same selections when the customer confirms a chat order.
* Add a separate, off-by-default setting for payment links alongside cash on delivery.

= 1.4.2 =
* Added opt-in WooCommerce payment links for confirmed, unpaid chat orders when cash on delivery is disabled.
* Added event-driven order status, verified payment, and merchant-confirmed dispatch updates for ReplyNext chat orders.

= 1.4.1 =
* Allow signed order-status lookup for orders created in the same ReplyNext chat, without asking the customer to repeat billing details.
* Keep the legacy limited lookup protected by exact billing-phone matching.
* Bound request size and order ID range; require HTTPS and operation-specific signatures.

= 1.4.0 =
* Added secure chat checkout that uses the WooCommerce store's required fields, live totals, explicit confirmation, and supported cash-on-delivery rules.
* Added bounded, signed WooCommerce order creation with durable idempotency and safe handling of uncertain outcomes.
* Added limited order-status lookup that requires an exact order reference and matching billing phone and returns status only.
* Improved live stock and variation checks so descriptive attributes are not mistaken for purchasable options.
* Preserved the legacy product sync, lightweight discovery index, widget provisioning, and existing connection security.

= 1.3.0 =
* Added secure automatic website-widget provisioning for connected WordPress sites.
* Added lightweight WooCommerce product discovery with canonical product links and primary images.
* Added live WooCommerce verification for current prices, sale status, availability, and variations.
* Improved bounded background catalog synchronization, including concurrency protection and widget-provisioning locking.
* Hardened tenant isolation, website-origin validation, and secure ReplyNext SaaS compatibility.
* Preserved the existing WooCommerce product synchronization path for backward compatibility.

= 1.1.1 =
* Keeps third-party WordPress admin notices above the branded ReplyNext dashboard header.

= 1.2.0 =
* Added Messenger and direct-call contact buttons.
* Added full WooCommerce catalog synchronization in 100-product batches.
* Added external-service and privacy disclosures.

= 1.1.0 =

* Added a top-level ReplyNext dashboard and guided onboarding.
* Added Contact Channels with WhatsApp click-to-chat.
* Added visual previews and modular settings pages.
* Added page and device display rules.
* Added `[replynextai_whatsapp]` shortcode.

= 1.0.1 =

* Added a Settings shortcut directly on the WordPress Plugins page.

= 1.0.0 =

* Initial AI Website Chat release.
