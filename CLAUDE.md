# CLAUDE.md — CartQuill

Guidance for AI coding agents working in this repo.

## What this is

A standalone **WooCommerce email-automation plugin** (PHP 8.1+, WooCommerce 8+), distributed on WordPress.org. It sends via `wp_mail`; deliverability is intentionally left to the store's own SMTP/ESP plugin (e.g. WP Mail SMTP) — CartQuill ships no bespoke sending integration. Extensions are distributed separately and attach through the seams below; this repository contains none of them.

## Extension seams

**This repository is the complete, ungated plugin.** It contains no licence check, plan, usage cap, or upgrade prompt, and is a complete product in its own right rather than a limited preview of anything. Treat that as a hard constraint on every change: nothing about tiers, metering, or billing belongs here, in code, in a docblock, or in a UI string.

Extensions ship separately and are developed in their own repository. They attach only through seams this plugin already exposes:

| Seam | Filter/action |
|---|---|
| Builder availability | `cartquill_builder_availability` |
| Pending flow save | `cartquill_flow_presave` |
| Step execution policy | `cartquill_meter` |
| Sending transports | `cartquill_register_senders` |
| Step actions | `cartquill_register_actions` |
| Builder UI components | `window.cartquillBuilderSlots` |

`src/<Name>/addon.php` is the bootstrap for each and `Plugin::load_addons()` includes whichever are present — a no-op when none is installed. `src/<Name>/early.php` is the same for the rare extension that must be running before `plugins_loaded`, because it registers an activation hook of its own; WordPress fires `plugins_loaded` before it includes the plugin file during an activation request, so anything deferred to `boot()` misses it.

Keep the seams honest: `Meter` + `NullMeter` are a pure no-op policy seam (`NullMeter` never defers and never counts), and `OpenAvailability` offers everything this plugin ships. Tests drive the seams through local doubles such as `FakeAvailability`, so they assert what the interface promises any implementer rather than the behaviour of one consumer.

The JS is compiled **inside the staged package**, so the shipped bundle is provably a build of the shipped source. `bin/verify-package.sh` asserts the built package carries no gating vocabulary and none of the extension paths; CI runs it on every push.

## Releasing

Publishing a GitHub release deploys core to WordPress.org (`.github/workflows/release.yml`). Before tagging, bump the version in **all four** places — `cartquill.php` (the `Version:` header *and* `CARTQUILL_VERSION`), `readme.txt` (`Stable tag:` *and* a new `= x.y.z =` changelog heading), and `package.json`. `bin/check-version.sh` enforces it, in CI and again at release; the directory resolves a release by `Stable tag:`, so drift there publishes a version it cannot serve.

`bin/verify-package.sh` holds the core/paid separation gate. CI and the release workflow both call it, so it is the one place that assertion lives — do not inline a copy into a workflow. A pre-release builds and verifies but never deploys.

`.wordpress-org/` holds the directory-listing artwork — icon and banners now, screenshots when they exist. It syncs to the top-level `assets/` path in SVN — outside trunk, so it never reaches an install — and the sync deletes anything not in it, making the directory the whole source of truth for that artwork. It is in `.distignore`, because the packaging rsync would otherwise ship it inside the plugin.

Do not hand-edit the icon or banners: `bin/render-wporg-assets.mjs` renders them (`npm install --no-save sharp && node bin/render-wporg-assets.mjs`), taking the mark from `assets/admin/icon.svg` so there is one source of truth for it. The directory requires a PNG fallback alongside an SVG icon, and the render is deterministic — re-running it on an unchanged mark produces byte-identical files.

The `screenshot-N.png` files are captures of the real admin, so they are only as current as the UI was when taken — re-shoot them when a screen they show changes, and keep the numbered captions under `== Screenshots ==` in `readme.txt` in step, since the directory pairs them by number.

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

**Product direction:** CartQuill is the no-code, multi-tool automation hub the marketing site (cartquill.com) describes — WooCommerce events fan out to Slack, Google Sheets, Mailchimp, and Twilio SMS via "recipes", — **not** an email-only tool. Email stays a first-class channel and every locked engine decision above still holds (SMS routes through the same step pipeline). SMS and metered actions are delivered by separately distributed extensions, wired through the seams in "Extension seams" above. Nothing about tiers, metering, or billing may appear in this repository. Flows are authored through a **linear drag-and-drop step-card builder** — an ordered list of step cards with per-step skip/exit gates and delays. This is the sanctioned no-code authoring surface; it matches the linear engine 1:1 and adds **no** branching. It is **in scope**.

The v1 exclusion list is a **contract** — nothing on it ships in v1: **branching / conditional journey graphs** (if/else split paths into different downstream steps — the *linear* step-card builder above is in scope, but the engine stays linear and we do not add branching), full CRM, A/B testing, advanced/predictive segmentation, multi-ESP-at-launch, resold sending, browse-abandonment, and the **multi-site console / white-label workflows / team roles & audit log** (deferred). Do not add features from this list "while you're in there."
