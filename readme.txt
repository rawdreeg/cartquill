=== CartQuill ===
Contributors: rawdreeg
Tags: woocommerce, email, automation, marketing, sms
Requires at least: 6.4
Tested up to: 7.0
Requires PHP: 8.1
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Install proven WooCommerce email flows, draft them with AI, and see revenue per flow. The free core sends through wp_mail.

== Description ==

CartQuill is a standalone WooCommerce automation plugin for store owners who want proven, revenue-driving flows without wiring up a heavyweight marketing suite. The free core is email-first; the paid Automations add-on grows it into a no-code, multi-tool hub (Slack, Google Sheets, Mailchimp, and Twilio SMS).

The **free core**:

* Install ready-made flows — welcome, post-purchase, abandoned cart, and win-back — from a built-in library, or build your own in a drag-and-drop step builder: an ordered list of step cards you reorder by dragging, each with its own copy, delay, and optional condition gates.
* Send through WordPress' own `wp_mail()`. No third-party sending account required to get started.
* Self-hosted open and click tracking (no external pixel service).
* Last-touch revenue attribution: every flow shows the order revenue it drove, inside a configurable attribution window.
* Compliance built in: a one-click unsubscribe link on every email (RFC 8058), a global suppression list checked before every send, consent-source recording, and WordPress privacy export/erase integration.

A **paid subscription** — Starter, Growth, or Agency — unlocks CartQuill's premium capabilities. There is no separate per-feature license key: one subscription unlocks everything its tier includes, and higher tiers unlock more.

* **Automations** (all paid tiers) — turn a WooCommerce event into a no-code multi-tool "recipe": *when* it fires, *if* a condition holds, *do* one or more actions across Slack, Google Sheets, Mailchimp, and Twilio SMS (plus email). Each tool connects *your own* account. Usage is metered by actions per month, with a higher cap on each tier.
* **AI Flow Generation** (Growth and up) — draft a whole flow, or rewrite a single step, from a short prompt. Generated copy always opens in the builder for your review; nothing is ever sent to customers automatically.

A few honest notes on the paid tiers:

* **Deliverability is your SMTP plugin's job.** CartQuill sends through WordPress' `wp_mail()`, exactly as it always has, so inbox placement follows however your site sends mail. For higher-volume sending or delivery insight, pair CartQuill with a dedicated SMTP/ESP plugin (such as WP Mail SMTP) connected to your own provider — CartQuill does not ship its own sending integration.
* **Mailchimp is audience sync, not sending.** The Mailchimp action upserts and tags a subscriber in *your* audience; CartQuill still sends every email through its own sender. Your customer email is never routed through, or resold via, Mailchimp.
* **Conditional logic** (data-driven branching, e.g. "only if cart value is over $50") is a Growth-and-up feature. Timed delays are available on every plan.
* **Coming soon:** the Agency plan's multi-store management, white-label workflows, and team roles & audit log are on the roadmap and not yet built; the Agency plan today ships as a higher monthly action cap.

CartQuill never operates or resells sending infrastructure — the Automations integrations connect accounts you already own.

== External services ==

This plugin's paid add-ons can connect to the external services listed below. None is contacted until you install the corresponding add-on, configure it, and use the feature. The free core contacts no external service.

= CartQuill AI service (AI Flow Generation add-on, paid vendor service) =

When you use "Generate with AI" or "Rewrite with AI", the plugin sends a request to the CartQuill AI proxy at https://api.cartquill.com to draft or rewrite email copy. Each request includes: the flow type you selected; store context (your store name, the tone you type, your store currency, and a few of your top product names); the copy you ask to rewrite; and your AI add-on license key, sent as a Bearer token for authentication. Requests are made only when you click **Generate draft** or **Rewrite**. This is a paid vendor service operated by CartQuill; the copy is drafted server-side and returned to the builder for review. The first request is made only after you explicitly acknowledge this disclosure in the plugin.

* Terms of Service: https://api.cartquill.com/legal/ai-terms
* Privacy Policy: https://api.cartquill.com/legal/ai-privacy

The four services below are used only by the **Automations** add-on, and only when a recipe you build runs a step that targets that tool. Each connects an account you already own.

= Slack (Automations add-on, your own workspace) =

When a recipe runs a Slack step, the plugin posts a message to the Slack incoming-webhook URL you configure (on https://hooks.slack.com). The request contains the alert text composed from the triggering WooCommerce event (for example an order number, order total, and customer name) as your recipe defines it. Requests are made only when a Slack step runs. Slack is your own workspace, governed by your agreement with Slack.

* Terms of Service: https://slack.com/terms-of-service
* Privacy Policy: https://slack.com/trust/privacy/privacy-policy

= Google Sheets (Automations add-on, your own Google account) =

When a recipe runs a Google Sheets step, the plugin appends a row to the spreadsheet you choose through the Google Sheets API (https://sheets.googleapis.com), authenticating with a Google service-account credential you provide (a short-lived token is obtained from https://oauth2.googleapis.com). The request contains the row values your recipe defines, rendered from the WooCommerce event. Requests are made only when a Sheets step runs. This is your own Google account, governed by your agreement with Google.

* Terms of Service: https://developers.google.com/terms
* Privacy Policy: https://policies.google.com/privacy

= Mailchimp (Automations add-on, your own account) =

When a recipe runs a Mailchimp step, the plugin creates or updates a subscriber in the audience you choose and applies tags, through the Mailchimp Marketing API (your data-center host, for example https://us1.api.mailchimp.com), using the API key you provide. The request contains the customer's email address and the tags your recipe defines. This action syncs your Mailchimp audience; it does not send email through Mailchimp. Requests are made only when a Mailchimp step runs. This is your own Mailchimp account, governed by your agreement with Mailchimp.

* Terms of Service: https://mailchimp.com/legal/terms/
* Privacy Policy: https://mailchimp.com/legal/privacy/

= Twilio (Automations add-on, your own account) =

When a recipe runs an SMS step, the plugin sends a text message through the Twilio API (https://api.twilio.com), using the Account SID, auth token, and from-number you provide. The request contains the recipient's phone number and the message text your recipe defines. To honor SMS opt-outs, the plugin also exposes a webhook that receives inbound STOP/START replies Twilio forwards, so unsubscribed numbers are added to your suppression list. Requests are made when an SMS step runs. This is your own Twilio account, governed by your agreement with Twilio.

* Terms of Service: https://www.twilio.com/en-us/legal/tos
* Privacy Policy: https://www.twilio.com/en-us/legal/privacy

== Frequently Asked Questions ==

= Does CartQuill require WooCommerce? =

Yes. CartQuill builds on WooCommerce 8.0 or newer for its order, customer, and checkout events. It will not activate without an active, compatible WooCommerce install.

= Does it send any of my data to third parties? =

Not in the free core — it sends through your site's own `wp_mail()` and tracks opens and clicks on your own domain. The paid add-ons contact external services only when you enable and use them; see the "External services" section above for exactly what is sent and when.

= Does the AI feature email my customers automatically? =

No. AI-generated and AI-rewritten copy always lands in the builder as a draft for you to review and activate. CartQuill never auto-sends generated copy.

= Do you resell email sending? =

No. CartQuill never operates or resells sending infrastructure — it always sends through your site's own `wp_mail()`. The Automations add-on's Mailchimp action syncs your audience (upsert and tag a subscriber) but never sends your email — CartQuill always sends through its own sender.

= What can the Automations add-on do? =

It turns a WooCommerce event into a no-code "recipe": *when* an order is paid, shipped, abandoned, or an account is created, *if* a condition holds (first-time customer, cart value, marketing opt-in, phone on file), *do* one or more actions — post to Slack, append a row to Google Sheets, sync a subscriber to Mailchimp, send a Twilio SMS, or send an email. Each tool uses your own account. Usage is metered by actions per month across the Starter, Growth, and Agency plans; conditional-logic branching is available from the Growth plan up. The Agency plan's multi-store management, white-label workflows, and team roles & audit log are coming soon.

= How is revenue attributed to a flow? =

Attribution is last-touch: when an order is placed, CartQuill matches the buyer to the most recent flow email sent to them within the attribution window (7 days by default, configurable). No multi-touch claims are made.

== Changelog ==

= 0.1.0 =
* Initial release: flow library and a drag-and-drop step builder, wp_mail sending, self-hosted open/click tracking, last-touch revenue attribution and reporting, one-click unsubscribe with global suppression, and WordPress privacy export/erase integration.
* AI Flow Generation add-on: draft a whole flow or rewrite a step from a prompt, for review in the builder.
* Automations add-on: no-code multi-tool recipes across Slack, Google Sheets, Mailchimp (audience sync), Twilio SMS, and email, with per-month action metering and Starter/Growth/Agency plans. Agency multi-store, white-label, and team roles are coming soon.

== Development ==

The step builder's JavaScript is compiled with [@wordpress/scripts](https://www.npmjs.com/package/@wordpress/scripts). The plugin ships both the compiled bundle (`assets/builder/build/`) and its complete, human-readable source (`assets/builder/src/`). To rebuild it from source: install Node dependencies with `npm install`, then run `npm run build` (or `npm run start` for a watching dev build). The source is also available in the project repository.
