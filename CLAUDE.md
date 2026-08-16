# CLAUDE.md — CartQuill

Guidance for AI coding agents working in this repo.

## What this is

A standalone **WooCommerce email-automation plugin** (PHP 8.1+, WooCommerce 8+). The plugin distributed on WordPress.org sends via `wp_mail`; **AI Flow Generation** and **Automations** ship separately as paid add-ons. Deliverability is intentionally left to the store's own SMTP/ESP plugin (e.g. WP Mail SMTP) — CartQuill ships no bespoke sending integration. See `README.md` for the model and `WooCommerce-Automation-PRD.md` / `WooCommerce-Automation-v1-Build-Spec.md` for the full contract.

## The core/paid boundary

**Core contains no licence check, plan, usage cap, or upgrade prompt.** It is a complete product on its own, not a limited preview of the paid one — the paid editions add capability rather than unlocking what is already there. Treat this as a hard constraint on every change.

One source tree builds both editions. `.distignore` has a `# @cartquill:paid` marker; `bin/build.sh free` strips everything below it, `bin/build.sh premium` keeps it. Below the marker live `src/Ai`, `src/Automations`, **`src/Licensing`**, the usage-metering implementations, `src/freemius.php`, the vendored **`freemius/`** SDK, `LicensePage`, `UsageNotice`, and `assets/builder/src/ai`.

Note that CartQuill does **not** use Freemius's own `__premium_only` stripping — this repo splits the editions itself and uploads only the premium package. So never let Freemius deploy the free version anywhere: with no `__premium_only` markers to strip, the "free version" its processor generates is byte-identical to the paid one. WordPress.org gets `build/cartquill` from `release.yml`, and nothing else.

The two editions install to **different folders** — `cartquill/` and `cartquill-premium/`. This is load-bearing. WordPress asks api.wordpress.org about every installed folder name, so a premium install in `cartquill/` gets offered the free directory version as an update; the SDK does strip that entry out of the update transient on every check, but only while it is loaded. A distinct folder removes the collision rather than defending against it. `premium_slug` in `src/freemius.php`, the folder `bin/build.sh` packages, and the assertion in `bin/verify-package.sh` all have to agree.

**Freemius decides what a store has paid for; nothing local does.** `OptionLicense` counts any non-empty string as a held plan and validates nothing, so `FreemiusBridge` *overrides* rather than unions on a premium build — `cartquill_fs_owns_plan()` is true wherever `src/freemius.php` exists, deliberately without asking whether the SDK actually loaded (keying off the live instance would mean deleting `freemius/` from a copied zip restores the local grant). `LicensePage` renders read-only in that mode. The `CARTQUILL_LOCAL_LICENSE` constant in `wp-config.php` is the only way back to local keys, and it is there so the add-ons can be built and demoed.

An unlicensed premium install must behave exactly like the free edition — complete and unmetered, not a crippled trial — which is why the no-tier limits fall through to `OptionLicense`'s uncapped defaults instead of zeroes.

Because of that, core code may **never** reference `CartQuill\Licensing\*` or the paid add-ons — not in a type hint, not in a docblock, not in a UI string. The premium layer attaches only through extension seams core already exposes:

| Seam | Filter/action | Premium hooks it with |
|---|---|---|
| Builder availability | `cartquill_builder_availability` | `LicensedAvailability` |
| Pending flow save | `cartquill_flow_presave` | `PlanGate::presave_filter()` |
| Step execution policy | `cartquill_meter` | `UsageMeter` |
| Builder UI components | `window.cartquillBuilderSlots` | the `ai` bundle entry |

`src/<Name>/addon.php` is the bootstrap for each; `Plugin::load_addons()` includes whichever are present. `Meter` + `NullMeter` stay in core as a pure no-op seam — `NullMeter` never defers and never counts.

The JS is compiled **inside the staged package**, after stripping, so the shipped bundle is provably a build of the shipped source. CI asserts both the file separation and that no licensing vocabulary survives in core.

## Releasing

Publishing a GitHub release deploys core to WordPress.org (`.github/workflows/release.yml`). Before tagging, bump the version in **all four** places — `cartquill.php` (the `Version:` header *and* `CARTQUILL_VERSION`), `readme.txt` (`Stable tag:` *and* a new `= x.y.z =` changelog heading), and `package.json`. `bin/check-version.sh` enforces it, in CI and again at release; the directory resolves a release by `Stable tag:`, so drift there publishes a version it cannot serve.

`bin/verify-package.sh` holds the core/paid separation gate. CI and the release workflow both call it, so it is the one place that assertion lives — do not inline a copy into a workflow. A pre-release builds and verifies but never deploys, and the premium zip is uploaded as a workflow artifact rather than a release asset (this repo is public).

The same release also uploads the premium package to Freemius, which is where paying customers' updates come from. It needs the `FREEMIUS_DEV_ID`, `FREEMIUS_PUBLIC_KEY` and `FREEMIUS_SECRET_KEY` repository secrets — all three are **developer**-scope credentials, from *My Profile → Keys* in the dashboard, not the product's own *Settings → Keys* (that is the plugin scope, which the action does not use). The product id is public and passed inline. Without the secrets the step warns and skips rather than failing, since WordPress.org has already been published by that point and a red job would misreport a release that shipped.

The upload action exits 0 on every failure path it has — two bare `die()` calls and a `catch` that only prints — so the release asserts the post-condition itself rather than trusting the step's status. It also overwrites the root `cartquill-premium.zip` with Freemius's round-trip and writes a `__free.zip` beside it; both are deleted, because that "free" package is byte-identical to the paid one and a future `*.zip` glob would publish it. **The secret key belongs in GitHub secrets and nowhere else** — never in plugin code, never in a build. Versions land as `pending`: promoting one to Released is a deliberate click in the Freemius dashboard, because a paid update reaches live stores with no directory review in front of it. Staged rollouts live there too and are the right way to ship to real stores.

The SDK in `freemius/` is vendored from [Freemius/wordpress-sdk](https://github.com/Freemius/wordpress-sdk) (currently **2.13.4**), copied verbatim from the release tarball. Update it by replacing the directory wholesale rather than patching in place, and re-run `bin/build.sh premium && bin/verify-package.sh premium`. It is GPL-3.0 while CartQuill is GPL-2.0-or-later; the combination is fine, and core never ships it.

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

**Product direction:** CartQuill is the no-code, multi-tool automation hub the marketing site (cartquill.com) describes — WooCommerce events fan out to Slack, Google Sheets, Mailchimp, and Twilio SMS via "recipes", billed by metered actions/month across Starter/Growth/Agency tiers — **not** an email-only tool. Email stays a first-class channel and every locked engine decision above still holds (SMS routes through the same step pipeline). SMS and metered "actions/month" billing are therefore **in scope — for the premium edition only**, wired through the seams in "The core/paid boundary" above. Nothing about tiers, metering, or billing may appear in core. Flows are authored through a **linear drag-and-drop step-card builder** — an ordered list of step cards with per-step skip/exit gates and delays. This is the sanctioned no-code authoring surface; it matches the linear engine 1:1 and adds **no** branching. It is **in scope**.

The v1 exclusion list is a **contract** — nothing on it ships in v1: **branching / conditional journey graphs** (if/else split paths into different downstream steps — the *linear* step-card builder above is in scope, but the engine stays linear and we do not add branching), full CRM, A/B testing, advanced/predictive segmentation, multi-ESP-at-launch, resold sending, browse-abandonment, and the **agency multi-site console / white-label workflows / team roles & audit log** (deferred — the Agency tier ships as a higher action cap only, with those features labeled "coming soon"). Do not add features from this list "while you're in there."
