# PRD — WooCommerce Email Automation Plugin (working name: FlowForge)

**Status:** Ready for build · **Source:** synthesized from the opportunity analysis + v1 build spec + grill decisions · **Distribution model:** free core on WordPress.org + paid add-ons (AI, Deliverability)

---

## Problem Statement

A WooCommerce store owner (or the agency running their store) wants email automation that actually makes money — abandoned-cart, welcome, post-purchase, and win-back flows that reach the inbox and whose revenue they can see. Today they're forced into a bad trade:

- The official plugin (**AutomateWoo**) installs easily but sends from their web server, so emails land in spam; it has no AI to help write flows, and only basic reporting, so they can't tell what a flow earned.
- The powerful platforms (**Klaviyo**, and similar) deliver well but are expensive, priced per contact (including people they never email), and pull their customer data off-site.

The result: non-technical merchants don't know *which* flows to build or *what to write*, can't tell if their emails are landing, and can't see the revenue each flow generates — and there is no affordable, WooCommerce-native product that solves all of this at once.

## Solution

A WooCommerce plugin that lets a merchant or agency stand up proven, revenue-generating email flows in minutes and see what they earn:

- **Install and go.** A free core plugin ships proven flow templates (abandoned cart, welcome, post-purchase, win-back) that install in a click and send immediately via WordPress's built-in mail — no third-party setup required.
- **Generate with AI.** A paid AI add-on generates and customizes flows and copy for the store, seeded with a curated library of high-converting WooCommerce flows.
- **Deliver for real, when it matters.** A paid Deliverability add-on connects the merchant's *own* email service (Resend first; SES/Postmark/SMTP later) to unlock real inbox delivery, bounce/complaint handling, automatic list suppression, and delivery reporting the free tier can't show.
- **See the money.** Core reporting attributes revenue to each flow (last-touch) so the user knows what's working, with sent/open/click stats; the Deliverability add-on adds true delivered/bounce data.

Free core keeps onboarding frictionless and drives WordPress.org distribution; the AI and Deliverability add-ons monetize the two highest-value capabilities without the vendor ever operating email infrastructure.

## User Stories

**Onboarding & core setup**

1. As a store owner, I want the plugin to work immediately after activation, so that I can send my first flow without configuring any external service.
2. As a store owner, I want the plugin to check that WooCommerce is active on install, so that I'm not left with a broken setup.
3. As a store owner, I want a short onboarding that points me to the flow library, so that I know what to do first.
4. As an agency, I want to install and configure the plugin per client store, so that each client runs their own flows independently.

**Flow templates (free core)**

5. As a store owner, I want a library of proven flow templates (abandoned cart, welcome, post-purchase, win-back), so that I don't have to design flows from scratch.
6. As a store owner, I want to install a template in one click, so that a working flow exists immediately.
7. As a store owner, I want each template to include ready-to-use copy, so that I can launch without writing emails myself.
8. As a store owner, I want to preview a template's steps and timing before activating, so that I understand what will be sent.
9. As a store owner, I want to activate and deactivate a flow, so that I control when automation runs.

**AI flow generation (paid add-on)**

10. As a store owner, I want AI to generate a flow tailored to my store, so that the copy and timing fit my products and tone.
11. As a store owner, I want AI to rewrite or vary the copy in a flow step, so that I can improve or A/B ideas manually.
12. As a store owner, I want to review and edit any AI-generated content before it goes live, so that nothing sends unreviewed.
13. As a store owner, I want AI suggestions grounded in proven WooCommerce flows, so that the output reflects what actually converts.
14. As a store owner, I want the AI add-on to be usage-limited fairly, so that costs stay predictable.

**Editing & configuring flows**

15. As a store owner, I want to edit a flow's steps, delays, subject lines, and body copy, so that I can customize it to my brand.
16. As a store owner, I want to set conditions on steps (e.g., only send if no purchase yet), so that customers don't get irrelevant emails.
17. As a store owner, I want to set the from-name and from-address, so that emails look like they come from my store.

**Triggers & enrollment**

18. As a store owner, I want an abandoned-cart flow to trigger when a customer leaves items without ordering, so that I can recover lost sales.
19. As a store owner, I want a welcome flow to trigger on a customer's first order or signup, so that I can build the relationship early.
20. As a store owner, I want a post-purchase flow to trigger on order completion, so that I can drive reviews and repeat purchases.
21. As a store owner, I want a win-back flow to trigger when a customer hasn't ordered in N days, so that I can re-engage lapsed customers.
22. As a store owner, I want a customer to exit a flow when they take the target action (e.g., they purchase), so that they stop receiving nudges.
23. As a store owner, I want a customer to never be enrolled twice in the same flow run, so that they aren't spammed with duplicates.

**Sending — core (wp_mail)**

24. As a store owner, I want the free tier to send through my site's existing mail setup, so that there's zero configuration to start.
25. As a store owner, I want the plugin to respect any SMTP plugin I already run, so that my current deliverability setup is honored.
26. As a store owner, I want to see that a message was sent, so that I know the flow is running.

**Deliverability add-on (paid)**

27. As a store owner, I want to connect my own email service (Resend/SES/Postmark/SMTP), so that my marketing emails actually reach the inbox.
28. As a store owner, I want a guided domain-authentication wizard (SPF/DKIM/DMARC), so that I pass modern bulk-sender rules without being an expert.
29. As a store owner, I want delivered, bounced, and complaint events recorded, so that I can trust my sending.
30. As a store owner, I want bounced and complained addresses automatically suppressed, so that I stop emailing dead or hostile addresses and protect my reputation.
31. As an agency, I want to connect a client's own email service, so that deliverability and reputation stay with the client.
32. As a store owner, I want to switch email providers without rebuilding my flows, so that I'm not locked in.

**Reporting & attribution**

33. As a store owner, I want to see revenue attributed to each flow, so that I know which automation earns its keep.
34. As a store owner, I want sent, open, and click counts per flow, so that I can gauge engagement.
35. As a store owner, I want the attribution window to be visible and explained, so that I trust the numbers.
36. As a store owner on the free tier, I want to be told when delivery is unconfirmed, so that I understand the limits of wp_mail and why I'd upgrade.
37. As a store owner with the Deliverability add-on, I want true delivered/bounce data in my reports, so that I can see inbox placement, not just sends.

**Compliance**

38. As a customer, I want every marketing email to include an unsubscribe link, so that I can opt out easily.
39. As a store owner, I want unsubscribes honored globally before every send, so that I never email someone who opted out.
40. As a store owner, I want data export and erasure to work with WordPress privacy tools, so that I can meet GDPR requests.

**Licensing, add-ons & packaging**

41. As a store owner, I want the free core to be genuinely useful on its own, so that I can trust the plugin before paying.
42. As a store owner, I want to buy the AI and Deliverability add-ons individually or as a Pro bundle, so that I only pay for what I need.
43. As a store owner, I want to enter a license key to unlock an add-on, so that upgrading is simple.
44. As an agency, I want a multi-site/agency license, so that I can use the paid add-ons across client stores.

**Reliability**

45. As a store owner, I want scheduled steps to send reliably even under load, so that flows fire on time.
46. As a store owner, I want the plugin never to double-send a step, so that customers aren't annoyed and my reputation is protected.

## Implementation Decisions

- **Monetization architecture:** free core (WordPress.org) + paid add-ons distributed off-site, licensed via **Freemius**. Add-ons: **AI Flow Generation** and **Deliverability**; a **Pro bundle** includes both.
- **Sending abstraction:** a single **`SenderInterface`** is the core seam for all sending. Core ships a `WpMailSender`. Add-ons register additional senders through a `register_sender()` extension hook and attach their own webhook ingestion. No sender other than wp_mail is bundled in core.
  - The interface encodes the key decision (from the spec): `send(Message $m): SendResult` returning at minimum an external id + accepted/failed status. This keeps the engine ignorant of *how* mail is sent.
- **No resold sending:** the Deliverability add-on connects the **customer's own** ESP account. The vendor never operates or resells sending infrastructure — so no per-email COGS, abuse policing, or shared-reputation liability.
- **Queue/scheduling:** all delays and step execution run on **Action Scheduler** (bundled with WooCommerce). No hand-rolled cron.
- **Flow engine:** trigger (WooCommerce hook) → create enrollment → for each step: check suppression → check conditions → render → `SenderInterface.send()` → record message → schedule next step.
- **Message status progression** (encodes what each tier can observe): `queued → sent` on wp_mail (plus self-hosted `opened`/`clicked` via pixel + wrapped-link redirect); the Deliverability add-on extends the same record with `delivered → bounced → complained` from ESP webhooks.
- **Attribution:** **last-touch**, computed on WooCommerce order placement by matching the buyer to the most recent flow message within a configurable **attribution window** (default 7 days). The window is surfaced in the UI. No multi-touch claims.
- **Schema (custom tables):** `flows` (definition + JSON steps + source template/ai), `flow_enrollments` (per-customer run + status + next_run_at), `messages` (per-send lifecycle + sender + external id + status), `attributions` (order → flow → revenue), `settings` (encrypted sender config, from-identity, license keys, suppression list).
- **AI service:** the AI add-on calls the vendor's **license-gated hosted proxy** (keys server-side, rate-limited), seeded with the curated flow library. Output is always user-editable and never auto-sent unreviewed in v1.
- **Compliance in core:** unsubscribe link on every email; a global suppression list checked as the first step of every send; WordPress privacy export/erase hooks.
- **Credentials:** sender credentials encrypted at rest; ESP webhook signatures verified.

## Testing Decisions

**What makes a good test here:** exercise *external behavior* through the highest seam, not internal methods. A good test fires a trigger (or feeds an event) and asserts what the system *does* — which messages get scheduled/sent, with what content and timing, and what revenue gets attributed — without sending real email.

**Primary seam — the `SenderInterface` (highest, preferred):** inject a `FakeSender` that records calls instead of sending. Almost every engine behavior is observable here. Tested behaviors:
- Trigger → correct enrollment created; steps scheduled at the right delays.
- Conditions honored (e.g., no send after the customer purchases; exit-on-conversion).
- Suppression honored — an unsubscribed/suppressed address is never handed to the sender.
- Idempotency — a given `(enrollment, step)` is never sent twice.

**Attribution seam:** given a set of recorded messages plus synthetic WooCommerce orders inside and outside the window, assert the correct revenue-per-flow output. Test the attribution behavior through its inputs/outputs, not its internals.

**Webhook ingestion seam (Deliverability add-on):** given a representative ESP webhook payload, assert the message status transitions and that bounced/complained addresses land on the suppression list. The handler is tested as a function of (payload) → (state change).

**Prior art / tooling:** standard WordPress plugin testing — `WP_UnitTestCase` on the WordPress PHPUnit harness, with **WooCommerce test factories** for orders/customers/products, and Action Scheduler executed within tests to advance scheduled steps. Prefer integration-style tests at the trigger→sender seam over unit tests of private methods.

**Seams to confirm with the developer/builder before implementation:** (1) `SenderInterface` as the universal sending seam with a `FakeSender` in tests; (2) attribution computed as an observable function of messages + orders; (3) webhook handler as a payload-in/state-out seam. These are proposed at the highest points available — flag if a lower or different seam is expected.

## Out of Scope

- Visual drag-and-drop journey builder; full CRM; SMS; A/B testing; advanced/predictive segmentation.
- **Bundled or resold sending** — the vendor never sends on the customer's behalf; the add-on connects the customer's own ESP.
- Supporting **all ESPs at launch** — Resend ships first; SES/Postmark/SMTP arrive as later add-ons/senders.
- Agency multi-site management console (agencies configure per client store in v1).
- Browse-abandonment tracking.
- Hosted SaaS delivery of the product itself (it is a self-hosted plugin).
- Internal implementation of the AI proxy backend beyond its contract with the add-on.
- The content/authority go-to-market plan (tracked separately — it's the distribution/moat work, not the product build).

## Further Notes

- **Build order matters:** the free core (engine → wp_mail → reporting → templates) ships to WordPress.org *before* any ESP/webhook work, so demand can be validated without touching sending-infrastructure complexity. The AI and Deliverability add-ons are layered on after.
- **The deliverability upsell is load-bearing:** because free-tier deliverability is wp_mail-grade (mediocre by design), the reporting must make the inbox problem *visible* — "revenue attributed, but delivery unconfirmed → add Deliverability to see inbox placement." If the free tier's deliverability sours first impressions, reconsider a small bundled free-send cap later.
- **The moat is not the code.** Defensibility is the curated, benchmarked flow IP inside the AI add-on plus the founder's brand as the go-to WooCommerce email authority — the software gets installs; the brand and curation retain.
- **Context:** solo, AI-assisted build by a non-developer/marketer founder. The `SenderInterface` + add-on model exists specifically to keep each shippable unit small and to keep the founder out of the email-operations business.
- **No issue tracker is configured** for this project, so this PRD is delivered as a document rather than published to a tracker with a `ready-for-agent` label. It can be published once a tracker (GitHub Issues, Linear, or a local markdown tracker via `setup-matt-pocock-skills`) is set up.
