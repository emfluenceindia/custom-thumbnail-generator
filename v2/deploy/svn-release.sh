#!/usr/bin/env bash
#
# Prepares a WordPress.org SVN checkout for a plugin release.
#
#   1. Reads the plugin folder: main plugin file, "Version:" header and the
#      readme.txt "Stable tag:". Stops if they differ.
#   2. Builds the list of release files (everything not matched by .distignore),
#      saves it as a manifest and checks it contains no hidden/.bak/v2 files.
#   3. Refreshes <svn-root>/trunk/ to exactly that list (SVN's .svn folders are
#      never touched) and verifies trunk/ matches the manifest file-for-file.
#   4. With --tag: creates <svn-root>/tags/<version>/ with the same files.
#
# It never runs svn add / svn rm / svn commit. Review, then commit yourself.
#
# Usage:
#   svn-release.sh [--tag] [--dry-run] [--manifest <file>] <plugin-dir> <svn-root>
#
# Example:
#   svn co https://plugins.svn.wordpress.org/custom-thumbnail-generator ~/svn/ctgen
#   v2/deploy/svn-release.sh --dry-run . ~/svn/ctgen     # preview only
#   v2/deploy/svn-release.sh --tag . ~/svn/ctgen         # trunk + tags/<version>

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
TAG=0
DRY_RUN=0
MANIFEST=""

usage() {
	sed -n '17,24p' "${BASH_SOURCE[0]}" | sed 's/^# \{0,1\}//'
	exit 1
}

die() {
	echo "ERROR: $*" >&2
	exit 1
}

POSITIONAL=()
while [ $# -gt 0 ]; do
	case "$1" in
		--tag) TAG=1 ;;
		--dry-run) DRY_RUN=1 ;;
		--manifest) shift; [ $# -gt 0 ] || usage; MANIFEST="$1" ;;
		-h|--help) usage ;;
		-*) die "unknown option: $1" ;;
		*) POSITIONAL+=("$1") ;;
	esac
	shift
done
[ ${#POSITIONAL[@]} -eq 2 ] || usage

command -v rsync >/dev/null || die "rsync is required."

# ---------------------------------------------------------------- 1. plugin
[ -d "${POSITIONAL[0]}" ] || die "plugin directory not found: ${POSITIONAL[0]}"
SRC="$(cd "${POSITIONAL[0]}" && pwd)"
IGNORE_FILE="$SRC/.distignore"
[ -f "$IGNORE_FILE" ] || die "$IGNORE_FILE not found."
[ -f "$SRC/readme.txt" ] || die "$SRC/readme.txt not found."

MAIN_FILE="$(grep -l -m1 '^[[:space:]/*#@]*Plugin Name:' "$SRC"/*.php 2>/dev/null | head -n1 || true)"
[ -n "$MAIN_FILE" ] || die "no main plugin file (with a 'Plugin Name:' header) in $SRC."

header_value() {
	grep -m1 -i "^[[:space:]/*#@]*$1:" "$2" | sed -E "s/^[^:]*:[[:space:]]*//; s/[[:space:]]+$//" | tr -d '\r'
}

VERSION="$(header_value 'Version' "$MAIN_FILE")"
STABLE_TAG="$(header_value 'Stable tag' "$SRC/readme.txt")"
SLUG="$(basename "$SRC")"

[ -n "$VERSION" ] || die "no 'Version:' header in $(basename "$MAIN_FILE")."
[[ "$VERSION" =~ ^[0-9A-Za-z.+-]+$ ]] || die "unexpected version string: '$VERSION'."
[ "$VERSION" = "$STABLE_TAG" ] || die "Version ($VERSION) in $(basename "$MAIN_FILE") does not match Stable tag ($STABLE_TAG) in readme.txt."
CONST_VERSION="$(sed -n -E "s/.*define\( *'CTGEN_PLUGIN_VERSION', *'([^']+)' *\).*/\1/p" "$MAIN_FILE" | head -n1)"
[ "$VERSION" = "$CONST_VERSION" ] || die "Version ($VERSION) in $(basename "$MAIN_FILE") does not match CTGEN_PLUGIN_VERSION ('$CONST_VERSION')."

# ----------------------------------------------------------------- svn root
mkdir -p "${POSITIONAL[1]}" 2>/dev/null || [ "$DRY_RUN" -eq 1 ] || die "cannot create ${POSITIONAL[1]}"
SVN_ROOT="$(cd "${POSITIONAL[1]}" 2>/dev/null && pwd || echo "${POSITIONAL[1]}")"
case "$SVN_ROOT/" in
	"$SRC/"*) die "the SVN path must be outside the plugin directory." ;;
esac
[ "$SVN_ROOT" != "/" ] && [ "$SVN_ROOT" != "$HOME" ] || die "refusing to use $SVN_ROOT as the SVN path."

TRUNK="$SVN_ROOT/trunk"
TAG_DIR="$SVN_ROOT/tags/$VERSION"

echo "Plugin:      $SLUG ($(basename "$MAIN_FILE"))"
echo "Version:     $VERSION (Stable tag: $STABLE_TAG)"
echo "Source:      $SRC"
echo "SVN path:    $SVN_ROOT"
[ -d "$SVN_ROOT/.svn" ] || echo "WARNING:     $SVN_ROOT is not an SVN checkout (no .svn folder). Files will be prepared, but run 'svn co' there before committing."
if [ "$TAG" -eq 1 ] && [ -e "$TAG_DIR" ]; then
	die "tags/$VERSION already exists. Bump the version, or remove the folder if it was never committed."
fi
echo

# --------------------------------------------------------- 2. file manifest
RSYNC_FILTER=(--exclude-from="$IGNORE_FILE" --exclude='.svn')
EMPTY_DIR="$(mktemp -d)"
trap 'rm -rf "$EMPTY_DIR"' EXIT

FILES="$(rsync -a --dry-run --out-format='%n' "${RSYNC_FILTER[@]}" "$SRC/" "$EMPTY_DIR/" | grep -v '/$' | grep -v '^\.$' | LC_ALL=C sort || true)"
[ -n "$FILES" ] || die "no files selected - check .distignore."
COUNT="$(printf '%s\n' "$FILES" | wc -l | tr -d ' ')"

BAD="$(printf '%s\n' "$FILES" | grep -E '(^|/)\.|\.bak[0-9]*$|^v2/|(^|/)node_modules/|\.sql$|\.log$|:Zone\.Identifier$' || true)"
[ -z "$BAD" ] || die "these files must not be released - add them to .distignore:
$BAD"
printf '%s\n' "$FILES" | grep -qx "$(basename "$MAIN_FILE")" || die "main plugin file missing from the release list."
printf '%s\n' "$FILES" | grep -qx 'readme.txt' || die "readme.txt missing from the release list."

echo "Release files ($COUNT):"
printf '  %s\n' $FILES
echo

if [ "$DRY_RUN" -eq 1 ]; then
	echo "DRY RUN: nothing written."
	echo "Would refresh: $TRUNK"
	[ "$TAG" -eq 1 ] && echo "Would create:  $TAG_DIR"
	exit 0
fi

[ -n "$MANIFEST" ] || MANIFEST="$SCRIPT_DIR/manifests/$SLUG-$VERSION.txt"
mkdir -p "$(dirname "$MANIFEST")"
printf '%s\n' "$FILES" > "$MANIFEST"
echo "Manifest saved: $MANIFEST"

# ------------------------------------------------------------- 3. trunk/
# Same filter as the manifest. --delete removes files that left the plugin
# (renamed/deleted); excluded paths, including .svn, are never deleted.
copy_release() {
	mkdir -p "$1"
	rsync -a --delete "${RSYNC_FILTER[@]}" "$SRC/" "$1/"

	local actual
	actual="$(cd "$1" && find . -name '.svn' -prune -o -type f -print | sed 's|^\./||' | LC_ALL=C sort)"
	if [ "$actual" != "$FILES" ]; then
		echo "ERROR: $1 does not match the manifest:" >&2
		diff <(printf '%s\n' "$FILES") <(printf '%s\n' "$actual") | sed 's/^</  missing:/; s/^>/  unexpected:/' | grep -E 'missing|unexpected' >&2
		echo "Remove unexpected files by hand (rsync never deletes excluded names such as *.bak)." >&2
		exit 1
	fi
	echo "OK: $1 matches the manifest ($COUNT files)."
}

copy_release "$TRUNK"

# ---------------------------------------------------------- 4. tags/<ver>/
if [ "$TAG" -eq 1 ]; then
	copy_release "$TAG_DIR"
fi

# ----------------------------------------------------------------- summary
echo
echo "Next steps - run these yourself in $SVN_ROOT and review before committing:"
echo "  svn status                                              # ? new, ! missing, M modified"
echo "  svn add --force trunk$([ "$TAG" -eq 1 ] && echo " tags/$VERSION")"
echo "  svn status | awk '/^!/ {print \$2}' | xargs -r svn rm   # files renamed/removed from the plugin"
echo "  svn ci -m \"Release $VERSION\""
if [ "$TAG" -eq 0 ]; then
	echo
	echo "No tag created. For a new version: bump Version + Stable tag, then rerun with --tag."
fi
