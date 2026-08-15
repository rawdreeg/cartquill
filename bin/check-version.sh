#!/usr/bin/env bash
#
# Assert every place the version is written agrees — and, when given an expected
# version, that they all equal it.
#
#   bin/check-version.sh          # the in-repo version strings agree
#   bin/check-version.sh 0.2.0    # ...and they are all 0.2.0 (a leading `v` is fine)
#
# WordPress.org resolves a release by the `Stable tag:` in readme.txt, not by the
# SVN tag the code was committed under. If that drifts from the plugin header the
# directory serves the wrong version — or none — so a release checks first.
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

# Each version string, read from the file that owns it.
header="$(sed -n 's/^[[:space:]]*\*[[:space:]]*Version:[[:space:]]*\([0-9][^[:space:]]*\).*/\1/p' "$ROOT/cartquill.php" | head -1)"
constant="$(sed -n "s/^define([[:space:]]*'CARTQUILL_VERSION',[[:space:]]*'\([^']*\)'.*/\1/p" "$ROOT/cartquill.php" | head -1)"
stable="$(sed -n 's/^Stable tag:[[:space:]]*\([^[:space:]]*\).*/\1/p' "$ROOT/readme.txt" | head -1)"
package="$(sed -n 's/^[[:space:]]*"version":[[:space:]]*"\([^"]*\)".*/\1/p' "$ROOT/package.json" | head -1)"

# An unreadable string means the file's shape changed and the patterns above
# went stale — that must fail loudly rather than compare two empty strings.
[ -n "$header" ]   || fail "could not read the Version: header from cartquill.php"
[ -n "$constant" ] || fail "could not read CARTQUILL_VERSION from cartquill.php"
[ -n "$stable" ]   || fail "could not read Stable tag: from readme.txt"
[ -n "$package" ]  || fail "could not read version from package.json"

[ "$constant" = "$header" ] || fail "CARTQUILL_VERSION ($constant) does not match the Version: header ($header)"
[ "$stable" = "$header" ]   || fail "readme.txt Stable tag ($stable) does not match the Version: header ($header)"
[ "$package" = "$header" ]  || fail "package.json version ($package) does not match the Version: header ($header)"

# The directory renders this section on the plugin page; shipping a version with
# no entry is the kind of thing that is only ever noticed after the fact.
grep -q "^= ${header} =" "$ROOT/readme.txt" || fail "readme.txt has no changelog entry '= ${header} ='"

if [ "$#" -gt 0 ]; then
	expected="${1#v}"
	[ "$expected" = "$header" ] || fail "releasing $expected, but the plugin says $header — bump the version on main first"
fi

echo "==> Version $header is consistent across cartquill.php, readme.txt and package.json"
