#!/usr/bin/env bash
#
# Assert a built package is what it claims to be.
#
#   bin/verify-package.sh core      # checks build/cartquill
#   bin/verify-package.sh premium   # checks build/premium/cartquill
#
# Core is a complete product, not a limited preview: it must contain no licence
# check, plan gate, usage cap or upgrade prompt, and none of the paid add-ons.
# Premium is the same tree with the paid section kept — and must actually carry
# it, autoloadable, with no dev cruft in either.
#
# CI runs this on every push and the release workflow runs it again before
# anything reaches WordPress.org, so the published package clears the same bar.
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

fail() {
	if [ -n "${GITHUB_ACTIONS:-}" ]; then
		echo "::error::$1"
	else
		echo "error: $1" >&2
	fi
	exit 1
}

# Dev and meta files that must not ship in either edition. `.wordpress-org` is
# directory artwork deployed separately, so it must not ride along in the plugin.
DEV_CRUFT=(
	tests composer.lock CLAUDE.md .claude .omc .wordpress-org
	node_modules package.json package-lock.json webpack.config.js
)

# Paid code core strips and premium keeps.
PAID_PATHS=(
	src/Ai src/Automations src/Licensing src/freemius.php
	src/Admin/AiGeneratePage.php src/Admin/LicensePage.php src/Admin/UsageNotice.php
	src/Metering/UsageMeter.php src/Metering/UsageStore.php
	src/Metering/WpdbUsageStore.php src/Metering/InMemoryUsageStore.php
	assets/builder/src/ai assets/builder/build/ai.js
)

verify_core() {
	local pkg="$ROOT/build/cartquill"
	[ -d "$pkg" ] || fail "no core package at build/cartquill — run bin/build.sh first"

	local entry
	for entry in "${PAID_PATHS[@]}" "${DEV_CRUFT[@]}"; do
		if [ -e "$pkg/$entry" ]; then
			fail "'$entry' leaked into core"
		fi
	done

	# Grep the shipped source (excluding vendor/ and the readme) for the
	# vocabulary of a gated product. `is_active()` with no argument is
	# WooCommerce's own, so it is allowed through.
	if grep -rniE "licen[cs]e key|plan gate|upgrade required|your plan|freemius|is_active\(|held plan" \
		"$pkg" --include='*.php' --include='*.js' --include='*.css' \
		| grep -v '/vendor/' | grep -v 'is_active()'; then
		fail "core references licensing or plan gating"
	fi

	if find "$pkg" -name '.DS_Store' | grep -q .; then
		fail ".DS_Store leaked into core"
	fi
	if find "$pkg" -name '*.test.js' | grep -q .; then
		fail "a *.test.js file leaked into core"
	fi

	[ -f "$pkg/vendor/autoload.php" ] || fail "built package is missing vendor/autoload.php"
	# Ships on purpose: Plugin Check reports missing_composer_json_file for a
	# vendor/ directory with no composer.json beside it.
	[ -f "$pkg/composer.json" ] || fail "built package is missing composer.json"
	[ -f "$pkg/assets/builder/build/index.js" ] || fail "built package is missing the compiled flow builder bundle"
	[ -f "$pkg/assets/builder/build/style-index.css" ] || fail "built package is missing the compiled flow builder stylesheet"

	echo "==> Core package verified: no paid code, no gating vocabulary, production autoloader present"
}

verify_premium() {
	local pkg="$ROOT/build/premium/cartquill"
	[ -d "$pkg" ] || fail "no premium package at build/premium/cartquill — run bin/build.sh premium first"

	local entry
	# The paid add-ons core strips must be PRESENT in premium...
	for entry in src/Ai/addon.php src/Automations/addon.php src/Admin/AiGeneratePage.php; do
		[ -e "$pkg/$entry" ] || fail "premium build is missing paid file '$entry'"
	done

	# ...and their classes must be in the optimized classmap so they autoload
	# (the classmap maps each paid class to its src/ path — assert on that).
	grep -q "src/Ai/AiAddon.php" "$pkg/vendor/composer/autoload_classmap.php" \
		|| fail "premium autoloader classmap is missing the paid AI classes"

	# ...while dev/meta files stay out of premium too.
	for entry in "${DEV_CRUFT[@]}"; do
		if [ -e "$pkg/$entry" ]; then
			fail "'$entry' leaked into the premium build"
		fi
	done

	if find "$pkg" -name '*.test.js' | grep -q .; then
		fail "a *.test.js file leaked into the premium build"
	fi

	[ -f "$pkg/vendor/autoload.php" ] || fail "premium package is missing vendor/autoload.php"
	# Building premium must not have clobbered the core zip.
	[ -f "$ROOT/build/cartquill.zip" ] || fail "the core zip disappeared while building premium"

	echo "==> Premium package verified: paid add-ons present and autoloadable, no dev cruft"
}

case "${1:-core}" in
	core|free) verify_core ;;
	premium)   verify_premium ;;
	*)         fail "unknown edition '${1:-}' (use 'core' or 'premium')" ;;
esac
