# FlowForge

> A standalone WooCommerce email-automation plugin: installs proven flows, generates them with AI, and reports revenue per flow. **Free core sends via `wp_mail`; deliverability and AI are paid add-ons.**

FlowForge closes the forced trade-off every WooCommerce merchant faces today — *great native data + bad deliverability* (self-hosted plugins) vs. *great deliverability + recurring per-contact cost + data off-site* (SaaS). It pairs native Woo data with AI-generated flows, honest revenue-per-flow reporting, and modern deliverability that runs on the **customer's own** email service.

## Model: free core + paid add-ons

| Component | Tier | Includes |
|---|---|---|
| **Core** (WordPress.org) | Free | Engine, 4–6 flow templates, `wp_mail` sending, basic reporting: sent + self-hosted open/click + last-touch revenue-per-flow |
| **AI Flow Generation** | Paid add-on | Generate/customize flows + copy via a license-gated hosted proxy |
| **Deliverability** | Paid add-on | Connect *your own* ESP (Resend first; SES/Postmark/SMTP later); delivered/bounce/complaint webhooks, auto-suppression, domain-auth wizard, inbox reporting |
| **Pro bundle** | Paid | AI + Deliverability together; à la carte also available |

The add-on never resells sending — it connects the customer's own ESP account, so the vendor carries no per-email cost, abuse policing, or reputation liability.

## Stack

- **WordPress plugin (PHP 8.1+)**, requires **WooCommerce 8+** (hard dependency check on activate).
- **Action Scheduler** (bundled with Woo) for all queues/delays — no hand-rolled cron.
- **`wp_mail`** in core; add-ons register extra senders via a `SenderInterface` + `register_sender()` hook.
- **Licensing:** Freemius (free core on WP.org + paid add-on/bundle licensing off-site).

## Architecture

```
trigger (Woo hook) → create flow_enrollment → Action Scheduler
  → step N: check suppression + conditions → render → SenderInterface.send() → write message row
  → schedule step N+1 (delay)
[core] open pixel / wrapped-link redirect → update message   [add-on] ESP webhook → delivered/bounce/complaint
Woo order placed → attribute to recent flow message (window) → revenue_per_flow
```

Custom tables: `flows`, `flow_enrollments`, `messages`, `attributions`, `settings`.

## Build sequence (tracer-bullet)

Free core ships to WordPress.org **before** any ESP/webhook complexity.

1. **Spine** — scaffold + Woo dependency + settings + one engine path + `wp_mail` send end-to-end.
2. **Engine** — Action Scheduler + tables + core flows firing trigger → delay → send → record.
3. **Core reporting** — self-hosted open/click + last-touch revenue-per-flow dashboard.
4. **Library** — 4–6 flow templates with copy → **ship free core.**
5. **AI add-on (paid)** — license-gated proxy generates/customizes flows + copy.
6. **Deliverability add-on (paid)** — Resend via customer key + domain-auth wizard + webhooks.
7. **Licensing/bundle** — Freemius gating + compliance pass.

Work is tracked as [GitHub Issues](../../issues) — vertical tracer-bullet slices, blockers first.

## Out of scope (v1 — the contract)

Visual drag-drop journey builder · full CRM · SMS · A/B testing · advanced/predictive segmentation · bundled/resold sending · all ESPs at once (Resend first) · agency multi-site console · browse-abandonment tracking.
