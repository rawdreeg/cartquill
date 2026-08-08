# CLAUDE.md — CartQuill

Guidance for AI coding agents working in this repo.

## What this is

A standalone **WooCommerce email-automation plugin** (PHP 8.1+, WooCommerce 8+). The plugin distributed on WordPress.org sends via `wp_mail`; **AI Flow Generation** and **Automations** ship separately as paid add-ons. Deliverability is intentionally left to the store's own SMTP/ESP plugin (e.g. WP Mail SMTP) — CartQuill ships no bespoke sending integration. See `README.md` for the model and `WooCommerce-Automation-PRD.md` / `WooCommerce-Automation-v1-Build-Spec.md` for the full contract.

## The core/paid boundary

**Core contains no licence check, plan, usage cap, or upgrade prompt.** It is a complete product on its own, not a limited preview of the paid one — the paid editions add capability rather than unlocking what is already there. Treat this as a hard constraint on every change.

One source tree builds both editions. `.distignore` has a `# @cartquill:paid` marker; `bin/build.sh free` strips everything below it, `bin/build.sh premium` keeps it. Below the marker live `src/Ai`, `src/Automations`, **`src/Licensing`**, the usage-metering implementations, `src/freemius.php`, `LicensePage`, `UsageNotice`, and `assets/builder/src/ai`.

Because of that, core code may **never** reference `CartQuill\Licensing\*` or the paid add-ons — not in a type hint, not in a docblock, not in a UI string. The premium layer attaches only through extension seams core already exposes:

| Seam | Filter/action | Premium hooks it with |
|---|---|---|
| Builder availability | `cartquill_builder_availability` | `LicensedAvailability` |
| Pending flow save | `cartquill_flow_presave` | `PlanGate::presave_filter()` |
| Step execution policy | `cartquill_meter` | `UsageMeter` |
| Builder UI components | `window.cartquillBuilderSlots` | the `ai` bundle entry |

`src/<Name>/addon.php` is the bootstrap for each; `Plugin::load_addons()` includes whichever are present. `Meter` + `NullMeter` stay in core as a pure no-op seam — `NullMeter` never defers and never counts.

The JS is compiled **inside the staged package**, after stripping, so the shipped bundle is provably a build of the shipped source. CI asserts both the file separation and that no licensing vocabulary survives in core.

## Commit conventions

- **No AI attribution in commits.** Never add `Co-Authored-By: Claude` or a "Generated with Claude Code" trailer. Write plain, imperative commit messages.
- Small, atomic commits. One vertical slice = one logical series of commits.

## Non-negotiable architecture decisions (locked — do not relitigate)

- **`SenderInterface` is the single sending seam.** `send(Message $m): SendResult` returns at least an external id + accepted/failed status. Core ships `WpMailSender`. Add-ons register senders via a `register_sender()` hook and attach their own webhook ingestion. The engine stays ignorant of *how* mail is sent. Tests inject a `FakeSender`.
- **No resold sending.** Any sending transport an add-on adds connects the customer's *own* provider account. The vendor never operates or resells sending infrastructure.
- **Queue = Action Scheduler** (bundled with Woo). Never hand-roll cron.
- **Flow engine step pipeline:** trigger (Woo hook) → create enrollment → per step: check suppression → check conditions → render → `SenderInterface.send()` → record message → schedule next step.
- **Suppression is the first thing every send does.** Global suppression list checked before every send.
- **Idempotency:** unique key on `(enrollment, step)` — never double-send.
- **Attribution is last-touch**, computed on Woo order placement by matching the buyer to the most recent flow message within a configurable **attribution window** (default 7d, surfaced in UI). No multi-touch claims.
- **Message status progression:** `queued → sent` on wp_mail (+ self-hosted `opened`/`clicked` via pixel + wrapped-link).
- **Credentials encrypted at rest; inbound webhook signatures verified.**
- **Compliance is core, not optional:** unsubscribe link on every email → enrollment + global suppression; store consent source; wire WP privacy export/erase hooks.

## Data model (custom tables)

`flows` (definition + JSON steps + source template/ai) · `flow_enrollments` (per-customer run + status + next_run_at) · `messages` (per-send lifecycle + sender + external id + status) · `attributions` (order → flow → revenue) · `settings` (encrypted sender config, from-identity, license keys, suppression list).

## Testing

Test **external behavior through the highest seam**, not private methods.

- **Primary seam — `SenderInterface`:** inject a `FakeSender` that records calls. Assert enrollment creation, step scheduling/delays, conditions (exit-on-conversion, no send after purchase), suppression, and idempotency.
- **Attribution seam:** given recorded messages + synthetic Woo orders inside/outside the window, assert revenue-per-flow output.
- **Tooling (decided):** the **primary** suite is fast and DB-free — plain PHPUnit with the core wired through its injected seams (`FakeSender`, in-memory repositories, `ArraySettings`, `FixedClock`), and the few direct WP function calls stubbed with **Brain\Monkey**. This keeps CI dependency-free and pushes logic out of WP-coupled classes. A `WP_UnitTestCase` integration layer (WordPress PHPUnit harness + WooCommerce factories + Action Scheduler advanced in-test) is added later for the genuinely DB/hook-coupled paths; it is not required for slices whose behavior is observable at the `SenderInterface`/repository seam. Prefer integration-style trigger→sender tests over unit tests of private methods.

## Scope discipline

**Product direction:** CartQuill is the no-code, multi-tool automation hub the marketing site (cartquill.com) describes — WooCommerce events fan out to Slack, Google Sheets, Mailchimp, and Twilio SMS via "recipes", billed by metered actions/month across Starter/Growth/Agency tiers — **not** an email-only tool. Email stays a first-class channel and every locked engine decision above still holds (SMS routes through the same step pipeline). SMS and metered "actions/month" billing are therefore **in scope — for the premium edition only**, wired through the seams in "The core/paid boundary" above. Nothing about tiers, metering, or billing may appear in core. Flows are authored through a **linear drag-and-drop step-card builder** — an ordered list of step cards with per-step skip/exit gates and delays. This is the sanctioned no-code authoring surface; it matches the linear engine 1:1 and adds **no** branching. It is **in scope**.

The v1 exclusion list is a **contract** — nothing on it ships in v1: **branching / conditional journey graphs** (if/else split paths into different downstream steps — the *linear* step-card builder above is in scope, but the engine stays linear and we do not add branching), full CRM, A/B testing, advanced/predictive segmentation, multi-ESP-at-launch, resold sending, browse-abandonment, and the **agency multi-site console / white-label workflows / team roles & audit log** (deferred — the Agency tier ships as a higher action cap only, with those features labeled "coming soon"). Do not add features from this list "while you're in there."
