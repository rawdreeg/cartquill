#!/usr/bin/env bash
#
# Build a production CartQuill package.
#
#   bin/build.sh            # free core  -> build/cartquill.zip
#   bin/build.sh free       # (the default, spelled out)
#   bin/build.sh premium    # free core + paid add-ons -> build/cartquill-premium.zip
#
# Both editions regenerate a no-dev optimized autoloader and strip the dev/meta
# files listed in .distignore. The free edition ADDITIONALLY strips everything
# below the `# @cartquill:paid` marker in .distignore, so it cannot ship (or
# unlock) paid code. The premium edition keeps that paid section, so the
# Freemius package ships the gated add-ons the license unlocks — assembled from
# the exact same tree, with no second source of truth.
#
# Each edition wipes and rebuilds only its own staging dir and zip, so
# `bin/build.sh free && bin/build.sh premium` leaves both zips side by side.
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
BUILD="$ROOT/build"

EDITION="${1:-free}"
# The plugin folder inside every zip is `cartquill/` (both editions install to
# the same slug — premium is a superset that replaces the free install). The
# free edition stages directly in build/ (where CI and Plugin Check expect it);
# premium stages under build/premium/ so the two never clobber each other.
case "$EDITION" in
	free)
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
		echo "error: unknown edition '$EDITION' (use 'free' or 'premium')" >&2
		exit 1
		;;
esac

STAGE="$STAGE_PARENT/cartquill"
ZIP="$BUILD/$SLUG.zip"

for tool in composer rsync zip npm; do
	command -v "$tool" >/dev/null 2>&1 || { echo "error: '$tool' is required" >&2; exit 1; }
done

echo "==> Building the $EDITION package"

echo "==> Building the flow builder front-end"
npm --prefix "$ROOT" ci
npm --prefix "$ROOT" run build

echo "==> Staging into $STAGE"
# Wipe only this edition's plugin folder, never a shared parent, so the other
# edition's zip survives (`build.sh free && build.sh premium` yields both).
rm -rf "${STAGE:?}"
mkdir -p "$STAGE"
# Anchor build/ and dist/ to the root so the nested compiled bundle
# (assets/builder/build) still ships; exclude node_modules entirely.
rsync -a --exclude='.git' --exclude='vendor' --exclude='node_modules' --exclude='/build' --exclude='/dist' "$ROOT/" "$STAGE/"

echo "==> Removing non-shipping files listed in .distignore"
in_paid=0
while IFS= read -r pattern || [ -n "$pattern" ]; do
	pattern="${pattern%%$'\r'}"
	# The marker opens the paid add-on section; the premium edition keeps
	# everything below it, the free edition strips it like anything else.
	if [ "$pattern" = '# @cartquill:paid' ]; then
		in_paid=1
		continue
	fi
	[ -z "$pattern" ] && continue
	case "$pattern" in
		\#*) continue ;;
		composer.json|composer.lock) continue ;; # kept for the install step below
	esac
	if [ "$in_paid" = 1 ] && [ "$KEEP_PAID" = 1 ]; then
		continue # premium ships the paid add-ons
	fi
	rm -rf "${STAGE:?}/${pattern#/}"
done < "$ROOT/.distignore"

echo "==> Scrubbing nested OS junk"
find "$STAGE" -name '.DS_Store' -delete

echo "==> Scrubbing JS test files (source ships, tests do not)"
find "$STAGE" -name '*.test.js' -delete

echo "==> Installing production dependencies"
composer install --no-dev --optimize-autoloader --no-interaction --working-dir="$STAGE"

echo "==> Stripping build metadata"
rm -f "$STAGE/composer.json" "$STAGE/composer.lock"
rm -rf "$STAGE/vendor/bin"

echo "==> Zipping"
rm -f "$ZIP"
( cd "$STAGE_PARENT" && zip -rqX "$ZIP" cartquill )

echo "==> Built $ZIP"
