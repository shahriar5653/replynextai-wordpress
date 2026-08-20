=== ReplyNext AI – AI Chat Assistant for WooCommerce ===
Contributors: replynextai
Tags: live chat, ai chatbot, customer support, woocommerce, whatsapp
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.2.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Add ReplyNext AI Website Chat and configurable WhatsApp, Messenger, and direct-call contact buttons to WordPress.

WooCommerce is optional. ReplyNext AI chat, the storefront widget, and the
WhatsApp, Messenger, and direct-call contact features work without WooCommerce.
WooCommerce is required only for the optional product catalog synchronization
feature.

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

= Free WhatsApp =

The free WhatsApp button uses the website owner's own phone number and opens a
standard `wa.me` conversation. It does not require a WhatsApp API, ReplyNext
account, or paid plan.

= ReplyNext AI Website Chat =

Existing ReplyNext clients can connect the AI Website Chat module configured in
their client portal by entering the ReplyNext server URL and Company ID.

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
* WooCommerce catalog synchronization in 100-product batches

== External services ==

This plugin connects to the ReplyNext AI service at https://replynextai.com when
AI Website Chat, connection status, or WooCommerce catalog synchronization is
enabled. Product names, prices, descriptions, categories, URLs, and image URLs
may be transmitted for catalog synchronization. If the optional logged-in user
identity setting is enabled, the logged-in WordPress user's name and email may
be sent to ReplyNext AI with chat requests. Review the [ReplyNext AI privacy
policy](https://replynextai.com/privacy-policy) before enabling these features.

The contact buttons open the configured WhatsApp, Messenger, or telephone URL
in the visitor's browser or device. Those services may process data under their
own privacy policies; this plugin does not request their APIs or permissions.

== Installation ==

1. Upload `replynextai-chat.zip` in Plugins → Add New → Upload Plugin.
2. Activate ReplyNext AI Chat & WhatsApp.
3. Open the new ReplyNext AI menu.
4. Configure Free WhatsApp, AI Website Chat, or both.
5. Test the website in a private browser window.

== Shortcodes ==

AI Website Chat:

`[replynextai_chat]`

`[replynextai_chat company_id="12" server_url="https://replynextai.com"]`

Free WhatsApp:

`[replynextai_whatsapp]`

`[replynextai_whatsapp number="8801XXXXXXXXX" message="Hello"]`

== Frequently Asked Questions ==

= Does the free WhatsApp module use the WhatsApp API? =

No. It opens a direct `wa.me` link using the configured phone number.

= Do I need a ReplyNext account? =

Not for Free WhatsApp. A ReplyNext account is required for AI Website Chat.

= Where do I find the Company ID? =

Open the ReplyNext client portal and go to Integrations → AI Website Chat.

= Can I control where the buttons appear? =

Yes. Display Rules supports selected page IDs, excluded page IDs, and separate
mobile/desktop visibility.

== Changelog ==

= 1.1.1 =
* Keeps third-party WordPress admin notices above the branded ReplyNext dashboard header.

= 1.2.0 =
* Added Messenger and direct-call contact buttons.
* Added full WooCommerce catalog synchronization in 100-product batches.
* Added external-service and privacy disclosures.

= 1.1.0 =

* Added a top-level ReplyNext dashboard and guided onboarding.
* Added a free WhatsApp click-to-chat module.
* Added visual previews and modular settings pages.
* Added page and device display rules.
* Added `[replynextai_whatsapp]` shortcode.

= 1.0.1 =

* Added a Settings shortcut directly on the WordPress Plugins page.

= 1.0.0 =

* Initial AI Website Chat release.
