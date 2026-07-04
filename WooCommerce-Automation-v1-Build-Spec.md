# v1 Build Spec — WooCommerce Automation Plugin

**Working name:** FlowForge (placeholder) · **One-liner:** A standalone WooCommerce plugin that installs proven email flows, generates them with AI, and reports revenue per flow — **free core sends via wp_mail; deliverability and AI are paid add-ons.** · **Audience for this doc:** your AI coding agent.

---

### Model: free core + paid add-ons (the native WP pattern)
- **Core works on install** via `wp_mail` — zero setup, like AutomateWoo. Good enough for low volume / sites that already run an SMTP plugin.
- **ESP support ships as a paid add-on** that connects the customer's **own** Resend/SES/Postmark account → you carry **no per-email cost, no abuse policing, no reputation liability.**
- **The add-on's paid value is the loop, not the connector** (a bare "connect Resend" form is undercut by the free WP Mail SMTP plugin): delivered/bounce/complaint webhooks → auto-suppression + domain-auth wizard + real inbox/delivery reporting. Sell it as *deliverability + inbox visibility*.

### Catalog
| Component | Tier | Includes |
|---|---|---|
| **Core** (WordPress.org) | Free | Engine, 4–6 flow templates, `wp_mail` sending, basic reporting: sent + self-hosted open/click + **last-touch revenue-per-flow** |
| **AI Flow Generation** | Paid add-on | Generate/customize flows + copy via license-gated proxy |
| **Deliverability** | Paid add-on | Connect *your own* ESP (Resend first; SES/Postmark/SMTP later); delivered/bounce/complaint webhooks, auto-suppression, domain-auth wizard, inbox reporting |
| **Pro bundle** | Paid | AI + Deliverability together; à la carte also available |

*Upsell mechanic:* on free/wp_mail you can't confirm delivery — surface "$X attributed, but delivery unconfirmed → add Deliverability to see inbox placement." The *absence* of delivery data is the upgrade hook.

---

### Stack & dependencies
- **WordPress plugin (PHP 8.1+)**, requires **WooCommerce 8+** (hard dependency check on activate).
- **Action Scheduler** (bundled with Woo) for all queues/delays — **do not hand-roll cron.**
- **`wp_mail`** in core; **add-ons register extra senders** via a `SenderInterface` + webhook hook.
- **LLM API** (Anthropic/OpenAI) via *your* hosted proxy, license-gated (AI add-on).
- **Licensing/distribution:** Freemius (free core on WP.org + paid add-on/bundle licensing off-site).

### Architecture (components)
1. **Flow engine** — trigger → enroll → (delay → conditions → render → send → record) → next step.
2. **Queue** — Action Scheduler drives every delayed step (`next_run_at`).
3. **Sender layer** — `SenderInterface`; core ships `WpMailSender`; **add-ons register `ResendSender`/`SesSender`/… + their webhook ingestion** through a `register_sender()` hook.
4. **AI service** — store context + flow type + curated library → steps + copy (add-on, via proxy).
5. **Reporting** — core: self-hosted open/click + last-touch revenue attribution; deliverability add-on extends with delivered/bounce/complaint.
6. **Woo data layer** — orders, customers, products feed triggers + attribution.

```
trigger (Woo hook) → create flow_enrollment → Action Scheduler
  → step N: check suppression + conditions → render → SenderInterface.send() → write message row
  → schedule step N+1 (delay)
[core] open pixel / wrapped-link redirect → update message      [add-on] ESP webhook → delivered/bounce/complaint
Woo order placed → attribute to recent flow message (window) → revenue_per_flow
```

### Data model (minimal — custom tables)
| Entity | Key fields | Purpose |
|---|---|---|
| `flows` | id, name, type, status, steps(JSON: `[{delay, subject, body, conditions}]`), source(template/ai) | Flow definitions |
| `flow_enrollments` | id, flow_id, customer_email, status(active/completed/exited/unsubscribed), current_step, next_run_at | One customer's run |
| `messages` | id, enrollment_id, flow_id, step_index, sender, ext_id, status(queued→sent→[delivered→opened→clicked→bounced]), sent_at | One email + lifecycle (status past "sent" needs the add-on) |
| `attributions` | order_id, flow_id, message_id, revenue, attributed_at | Revenue credited to a flow |
| `settings` | sender_config(encrypted), from_name/email, license_keys, suppression_list | Config + opt-outs |

### Core flows (v1)
| Flow | Trigger | Default steps |
|---|---|---|
| Abandoned cart | Cart w/ captured email, no order after N min | t+1h, t+24h |
| Welcome | First order / newsletter signup | immediate, t+3d |
| Post-purchase | Order completed | t+0, t+14d (cross-sell) |
| Win-back | Scheduled scan: last order > N days | single + optional follow-up |
| *(optional)* Review request | X days after completion | single |

### Sending
- **Core (`WpMailSender`):** works on install, respects any site SMTP. Caps message status at "sent" (+ self-hosted open/click). No delivered/bounce data — by design.
- **Deliverability add-on:** `ResendSender` (then SES/Postmark/SMTP) using the **customer's** key; domain-auth wizard; consumes delivered/opened/clicked/bounced webhooks → status + **auto-suppression**. This is the paid value — *what landed + clean list*, not "it sends."

### AI generation (add-on)
- Input: store context + flow type. Output: ordered steps + copy, **seeded with your curated high-converting Woo library** (the moat). Route through the **license-gated proxy**; rate-limit; user edits before activating. Never auto-send unreviewed output in v1.

### Reporting
- **Core (wp_mail):** sent + self-hosted open(pixel)/click(wrapped links) + **last-touch revenue-per-flow** (sent→order within window; default 7d; surface the window). Label delivery "unconfirmed."
- **+ Deliverability add-on:** real delivered/bounce/complaint + inbox signals. Keep attribution last-touch and transparent — wrong numbers kill trust faster than missing features.

### Admin UI screens (keep to 5)
Onboarding → Flow library (install / AI-generate) → Flow detail (edit, activate) → Reporting dashboard → Settings (sender, license).

### Compliance — core, not optional
Every email carries an **unsubscribe link** → sets enrollment + **global suppression**, honored before *every* send. Store consent source. Wire WP privacy export/erase hooks (GDPR).

---

### OUT of scope (the contract — nothing here ships in v1)
❌ Visual drag-drop journey builder ❌ Full CRM ❌ SMS ❌ A/B testing ❌ Advanced/predictive segmentation ❌ Bundled/resold sending (you connect *their* ESP, never resell) ❌ All ESPs at once (ship Resend; add others as later add-ons) ❌ Agency multi-site console ❌ Browse-abandonment tracking

### Build sequence (tracer-bullet — free core ships before any ESP complexity)
1. **Spine:** core scaffold + Woo dependency + settings + one engine path + **`wp_mail` send** end-to-end.
2. **Engine:** Action Scheduler + the tables + core flows firing trigger→delay→send→record.
3. **Core reporting:** self-hosted open/click + last-touch revenue-per-flow dashboard.
4. **Library:** 4–6 flow templates with your copy → **ship free core to WordPress.org.**
5. **AI add-on (paid):** license-gated proxy generates/customizes flows + copy.
6. **Deliverability add-on (paid):** Resend via customer key + domain-auth wizard + webhooks → delivered/bounce/complaint + auto-suppression + inbox reporting (`SenderInterface` swap).
7. **Licensing/bundle:** Freemius core + add-on/bundle gating + compliance pass.

### Builder gotchas
- **Idempotency:** unique key on `(enrollment, step)`; never double-send.
- **Email capture** for abandoned cart is the hardest data bit — grab the email as early as possible (checkout field/account); don't over-engineer it.
- **ESP add-on = customer's own account** → no resale, no COGS/abuse for you. A bare connector is undercut by free SMTP plugins; the value is the webhook→bounce/suppression/reporting loop.
- Suppression check is the **first** thing every send does. Encrypt credentials at rest; verify webhook signatures.
- Use Action Scheduler — don't hand-roll cron.
