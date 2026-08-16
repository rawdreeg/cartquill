# CartQuill

Abandoned cart recovery and email automation for WooCommerce. Recover carts, welcome
buyers, win back lapsed customers — and see the revenue each flow actually drove.

Install it from the [WordPress.org plugin directory](https://wordpress.org/plugins/cartquill/);
this repository is the source it is built from.

**Requires** WordPress 6.4+, WooCommerce 8+, PHP 8.1+.

## What's here

This repository is the complete plugin. It contains no licence check, no plan, no
usage cap and no upgrade prompt — every feature it ships works for everyone, and it
is a finished product rather than a preview of a paid one. Extensions are
distributed separately and attach through the hooks below; none of them live here.

```
cartquill.php            plugin header, autoloader, composition entry point
src/Engine/              the flow engine: enrolment, step pipeline, conditions
src/Builder/             the drag-and-drop step-card builder's catalog and validation
src/Sender/              SenderInterface + the wp_mail sender
src/Persistence/         custom tables and repositories
src/Compliance/          unsubscribe, suppression, privacy export/erase
src/Attribution/         last-touch revenue attribution
assets/builder/src/      the builder front-end (React, @wordpress/scripts)
```

## Develop

```bash
composer install
npm install

vendor/bin/phpunit        # fast, DB-free suite
npm run test:js           # builder front-end
npm run build             # compile the builder bundle
```

The primary test suite is deliberately DB-free: the engine is exercised through its
injected seams (`FakeSender`, in-memory repositories, `FixedClock`), with the few
direct WordPress calls stubbed via Brain\Monkey. A `WP_UnitTestCase` layer covers
the genuinely DB- and hook-coupled paths:

```bash
npm run env:start                 # wp-env: WordPress + WooCommerce + MySQL (Docker)
npm run test:integration:setup    # fetch the PHPUnit 9 phar the WP suite needs
npm run test:integration
npm run env:destroy
```

## Build a release package

```bash
bash bin/build.sh          # -> build/cartquill.zip
bash bin/verify-package.sh core
```

`bin/verify-package.sh` is the gate: it asserts the built package carries a
production autoloader, the compiled builder bundle, no dev or editor cruft, and no
licensing or plan-gating vocabulary anywhere in the shipped source. CI runs it on
every push, and the release workflow runs it again before anything reaches
WordPress.org.

Publishing a GitHub release deploys to the directory. Bump the version in all four
places first — `cartquill.php` (the `Version:` header *and* `CARTQUILL_VERSION`),
`readme.txt` (`Stable tag:` *and* a new changelog heading), and `package.json`;
`bin/check-version.sh` enforces it.

## Extending it

Extensions ship as separate plugins and attach only through seams this plugin
exposes. Nothing needs to be patched.

| Seam | Hook |
|---|---|
| Register a sending transport | `cartquill_register_senders` |
| Register step actions | `cartquill_register_actions` |
| Which catalog entries are offered | `cartquill_builder_availability` |
| Inspect a flow before it saves | `cartquill_flow_presave` |
| Step execution policy | `cartquill_meter` |
| Builder UI components | `window.cartquillBuilderSlots` |

An extension installed at `src/<Name>/` is picked up from its `addon.php`, or from
`early.php` if it must run before `plugins_loaded` — WordPress fires that hook
*before* it includes the plugin file during an activation request, so anything
needing its own `register_activation_hook()` has to be included earlier.

`SenderInterface` is the single sending seam and the engine stays ignorant of how
mail is sent. Deliverability is deliberately left to the store's own SMTP/ESP
plugin; CartQuill ships no bespoke sending integration and never resells sending.

## Licence

GPL-2.0-or-later. See `readme.txt` for the user-facing description and changelog.
