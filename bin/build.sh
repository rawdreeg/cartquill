#!/usr/bin/env bash
#
# Build the production CartQuill package -> build/cartquill.zip
#
# Regenerates a no-dev optimized autoloader and strips the dev/meta files listed
# in .distignore.
#
# The JavaScript is compiled INSIDE the staged package, which is what guarantees
# the shipped bundle is an exact build of the shipped source.
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
BUILD="$ROOT/build"

# `core` and `free` are accepted so existing callers keep working; this
# repository builds one package.
case "${1:-core}" in
	core|free) ;;
	*) echo "error: unknown edition '${1:-}' (this repository builds only 'core')" >&2; exit 1 ;;
esac

SLUG="cartquill"
STAGE_PARENT="$BUILD"
STAGE="$STAGE_PARENT/cartquill"
ZIP="$BUILD/$SLUG.zip"

for tool in composer rsync zip npm; do
	command -v "$tool" >/dev/null 2>&1 || { echo "error: '$tool' is required" >&2; exit 1; }
done

echo "==> Building the CartQuill package"

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

# Read .distignore into the list of dev/meta files to strip. They come out after
# the front-end is compiled, since package.json and webpack.config.js are needed
# until then.
dev_patterns=()
while IFS= read -r pattern || [ -n "$pattern" ]; do
	pattern="${pattern%%$'\r'}"
	[ -z "$pattern" ] && continue
	case "$pattern" in
		\#*) continue ;;
		composer.json|composer.lock) continue ;; # kept for the install step below
	esac
	dev_patterns+=( "$pattern" )
done < "$ROOT/.distignore"

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
( cd "$STAGE_PARENT" && zip -rqX "$ZIP" cartquill )

echo "==> Built $ZIP"
