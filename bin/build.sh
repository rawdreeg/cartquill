#!/usr/bin/env bash
#
# Build a production CartQuill package.
#
#   bin/build.sh            # core                -> build/cartquill.zip
#   bin/build.sh core       # (the default, spelled out; `free` is an alias)
#   bin/build.sh premium    # core + paid add-ons -> build/cartquill-premium.zip
#
# Both editions regenerate a no-dev optimized autoloader and strip the dev/meta
# files listed in .distignore. The core edition ADDITIONALLY strips everything
# below the `# @cartquill:paid` marker in .distignore, so it cannot ship (or
# unlock) paid code — including the vendored Freemius SDK, which is what makes
# core provably free of any licence check. The premium edition keeps that paid
# section, so the Freemius package ships the gated add-ons the license unlocks
# — assembled from the exact same tree, with no second source of truth.
#
# The JavaScript is compiled INSIDE the staged package, after the paid section
# has been stripped. That is what guarantees the shipped bundle is an exact build
# of the shipped source: core's builder is compiled from a tree with no add-on
# entry point in it, so no add-on code can end up in its bundle.
#
# Each edition wipes and rebuilds only its own staging dir and zip, so
# `bin/build.sh core && bin/build.sh premium` leaves both zips side by side.
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
BUILD="$ROOT/build"

EDITION="${1:-core}"
# The two editions install to DIFFERENT folders — `cartquill/` and
# `cartquill-premium/` — and that is deliberate, not cosmetic.
#
# WordPress asks api.wordpress.org about every installed folder name. A premium
# install sitting in `cartquill/` therefore gets offered the free directory
# version as an update, and the only thing standing between a paying customer
# and a silent downgrade is the SDK stripping that entry out of the update
# transient on every check. A distinct folder removes the collision instead of
# defending against it, and it is the name the SDK's own auto-installer uses
# (`premium_slug` in src/freemius.php — the two MUST agree).
#
# The core edition stages directly in build/ (where CI and Plugin Check expect
# it); premium stages under build/premium/ so the two never clobber each other.
case "$EDITION" in
	core|free) # `free` kept as an alias so existing callers keep working.
		SLUG="cartquill"
		STAGE_PARENT="$BUILD"
		KEEP_PAID=0
		;;
	premium)
		SLUG="cartquill-premium"
		STAGE_PARENT="$BUILD/premium"
		KEEP_PAID=1
		;;
	*)
		echo "error: unknown edition '$EDITION' (use 'core' or 'premium')" >&2
		exit 1
		;;
esac

# The folder inside the zip carries the slug, so the package extracts straight
# into the plugin directory the edition is meant to occupy.
STAGE="$STAGE_PARENT/$SLUG"
ZIP="$BUILD/$SLUG.zip"

for tool in composer rsync zip npm; do
	command -v "$tool" >/dev/null 2>&1 || { echo "error: '$tool' is required" >&2; exit 1; }
done

echo "==> Building the $EDITION package"

echo "==> Installing front-end build dependencies"
npm --prefix "$ROOT" ci

echo "==> Staging into $STAGE"
# Wipe only this edition's plugin folder, never a shared parent, so the other
# edition's zip survives (`build.sh core && build.sh premium` yields both).
rm -rf "${STAGE:?}"
mkdir -p "$STAGE"
# Anchor build/ and dist/ to the root so the nested compiled bundle
# (assets/builder/build) still ships; exclude node_modules entirely.
rsync -a --exclude='.git' --exclude='vendor' --exclude='node_modules' --exclude='/build' --exclude='/dist' "$ROOT/" "$STAGE/"

# Read .distignore into two lists: the dev/meta entries above the marker, and the
# paid entries below it. They are stripped at different points — paid code must be
# gone BEFORE the front-end is compiled, while the dev/meta files (package.json,
# webpack.config.js, …) are needed until after it.
dev_patterns=()
paid_patterns=()
in_paid=0
while IFS= read -r pattern || [ -n "$pattern" ]; do
	pattern="${pattern%%$'\r'}"
	if [ "$pattern" = '# @cartquill:paid' ]; then
		in_paid=1
		continue
	fi
	[ -z "$pattern" ] && continue
	case "$pattern" in
		\#*) continue ;;
		composer.json|composer.lock) continue ;; # kept for the install step below
	esac
	if [ "$in_paid" = 1 ]; then
		paid_patterns+=( "$pattern" )
	else
		dev_patterns+=( "$pattern" )
	fi
done < "$ROOT/.distignore"

if [ "$KEEP_PAID" = 0 ]; then
	echo "==> Removing paid code before the front-end is compiled"
	for pattern in "${paid_patterns[@]}"; do
		rm -rf "${STAGE:?}/${pattern#/}"
	done
fi

echo "==> Building the flow builder front-end from the staged source"
# Start from an empty output dir so a bundle rsynced in from the working tree
# (for instance a premium `ai.js` left over from an earlier build) cannot survive
# into this edition's package.
rm -rf "${STAGE:?}/assets/builder/build"
# Borrow the root's installed dependencies rather than reinstalling them in the
# stage; the symlink is removed before the dev/meta strip below.
ln -s "$ROOT/node_modules" "$STAGE/node_modules"
npm --prefix "$STAGE" run build
rm -f "$STAGE/node_modules"

echo "==> Removing non-shipping dev and meta files"
for pattern in "${dev_patterns[@]}"; do
	rm -rf "${STAGE:?}/${pattern#/}"
done

echo "==> Scrubbing nested OS junk"
find "$STAGE" -name '.DS_Store' -delete

echo "==> Scrubbing JS test files (source ships, tests do not)"
find "$STAGE" -name '*.test.js' -delete

echo "==> Installing production dependencies"
composer install --no-dev --optimize-autoloader --no-interaction --working-dir="$STAGE"

echo "==> Stripping build metadata"
# composer.json ships: Plugin Check flags a vendor/ dir without it
# (missing_composer_json_file). The lock file and binaries stay out.
rm -f "$STAGE/composer.lock"
rm -rf "$STAGE/vendor/bin"

echo "==> Zipping"
rm -f "$ZIP"
( cd "$STAGE_PARENT" && zip -rqX "$ZIP" "$SLUG" )

echo "==> Built $ZIP"
