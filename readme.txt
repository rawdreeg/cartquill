=== FlowForge ===
Contributors: rawdreeg
Tags: woocommerce, email, automation, marketing, abandoned cart
Requires at least: 6.4
Tested up to: 6.8
Requires PHP: 8.1
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Install proven WooCommerce email flows, draft them with AI, and see revenue per flow. The free core sends through wp_mail.

== Description ==

FlowForge is a standalone WooCommerce email-automation plugin for store owners who want proven, revenue-driving email flows without wiring up a heavyweight marketing suite.

The **free core**:

* Install ready-made flows — welcome, post-purchase, abandoned cart, and win-back — from a built-in library, or build your own in a simple editor.
* Send through WordPress' own `wp_mail()`. No third-party sending account required to get started.
* Self-hosted open and click tracking (no external pixel service).
* Last-touch revenue attribution: every flow shows the order revenue it drove, inside a configurable attribution window.
* Compliance built in: a one-click unsubscribe link on every email (RFC 8058), a global suppression list checked before every send, consent-source recording, and WordPress privacy export/erase integration.

Two **paid add-ons** extend the core (each unlocked with its own license key):

* **AI Flow Generation** — draft a whole flow, or rewrite a single step, from a short prompt. Generated copy always opens in the editor for your review; nothing is ever sent to customers automatically.
* **Deliverability** — connect *your own* Resend account to send at scale, run a guided sending-domain authentication wizard, and ingest delivery/bounce/complaint webhooks that feed inbox reporting and auto-suppression.

FlowForge never operates or resells sending infrastructure. The Deliverability add-on connects the account you already own.

== External services ==

This plugin can connect to two external services. Neither is contacted until you configure and use the corresponding feature.

= FlowForge AI service (AI Flow Generation add-on, paid vendor service) =

When you use "Generate with AI" or "Rewrite with AI", the plugin sends a request to the FlowForge AI proxy at https://proxy.flowforge.app to draft or rewrite email copy. Each request includes: the flow type you selected; store context (your store name, the tone you type, your store currency, and a few of your top product names); the copy you ask to rewrite; and your AI add-on license key, sent as a Bearer token for authentication. Requests are made only when you click **Generate draft** or **Rewrite**. This is a paid vendor service operated by FlowForge; the copy is drafted server-side and returned to your editor for review. The first request is made only after you explicitly acknowledge this disclosure in the plugin.

* Terms of Service: https://flowforge.app/legal/ai-terms
* Privacy Policy: https://flowforge.app/legal/ai-privacy

= Resend (Deliverability add-on, your own account) =

If you enable the Deliverability add-on and connect your own Resend account, the plugin sends email through the Resend API at https://api.resend.com using the API key you provide. Each send includes the sender (from) name and address, the recipient's email address, the subject line, the HTML body, and the list-unsubscribe header of the automation email. During domain setup the plugin also sends your sending-domain name to create and verify it. Requests are made when a flow email is sent and when you run the domain-authentication wizard. Resend is your own third-party account, governed by your agreement with Resend.

* Terms of Service: https://resend.com/legal/terms-of-service
* Privacy Policy: https://resend.com/legal/privacy-policy

== Frequently Asked Questions ==

= Does FlowForge require WooCommerce? =

Yes. FlowForge builds on WooCommerce 8.0 or newer for its order, customer, and checkout events. It will not activate without an active, compatible WooCommerce install.

= Does it send any of my data to third parties? =

Not in the free core — it sends through your site's own `wp_mail()` and tracks opens and clicks on your own domain. The two paid add-ons contact external services only when you enable and use them; see the "External services" section above for exactly what is sent and when.

= Does the AI feature email my customers automatically? =

No. AI-generated and AI-rewritten copy always lands in the editor as a draft for you to review and activate. FlowForge never auto-sends generated copy.

= Do you resell email sending? =

No. The Deliverability add-on connects the Resend account you own; FlowForge never operates or resells sending infrastructure.

= How is revenue attributed to a flow? =

Attribution is last-touch: when an order is placed, FlowForge matches the buyer to the most recent flow email sent to them within the attribution window (7 days by default, configurable). No multi-touch claims are made.

== Changelog ==

= 0.1.0 =
* Initial release: flow library and editor, wp_mail sending, self-hosted open/click tracking, last-touch revenue attribution and reporting, one-click unsubscribe with global suppression, and WordPress privacy export/erase integration.
* AI Flow Generation add-on: draft and rewrite flow copy for editor review.
* Deliverability add-on: bring-your-own Resend sending, domain-authentication wizard, and delivery/bounce/complaint webhook ingestion with auto-suppression and inbox reporting.
