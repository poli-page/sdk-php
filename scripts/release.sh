#!/usr/bin/env bash
# scripts/release.sh — primary publishing path for poli-page/sdk
#
# What this script does:
#   1. Pre-flight checks (clean working tree, on `main`, branch up-to-date,
#      tag doesn't already exist, VERSION constant matches the requested tag).
#   2. Runs the full CI gate locally: composer validate, lint, phpstan,
#      composer audit, phpunit unit suite.
#   3. Prompts for confirmation, then creates a signed annotated tag
#      `vX.Y.Z` locally — never pushes for you.
#
# Run from a clean repo on `main`:
#     ./scripts/release.sh                 # uses VERSION constant in src
#     ./scripts/release.sh 1.2.3           # explicit
#     ./scripts/release.sh 1.2.3 --dry-run # everything except the tag
#
# Packagist auto-indexes via the configured GitHub webhook within seconds
# of `git push origin vX.Y.Z`. There is no separate `composer publish`
# command — push the tag yourself when ready.

set -euo pipefail

# Resolve repo root regardless of where the script was invoked from.
REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$REPO_ROOT"

VERSION_FILE="src/Internal/Version.php"
DRY_RUN=0
EXPECTED_VERSION=""

usage() {
    cat <<EOF
Usage: $(basename "$0") [VERSION] [--dry-run]

Without arguments, the script reads the VERSION constant from
$VERSION_FILE and tags the matching SemVer.

Options:
  --dry-run    Run all checks but skip tag creation.
  -h, --help   Show this help.
EOF
}

for arg in "$@"; do
    case "$arg" in
        --dry-run) DRY_RUN=1 ;;
        -h|--help) usage; exit 0 ;;
        -*)        echo "unknown flag: $arg" >&2; usage >&2; exit 2 ;;
        *)         EXPECTED_VERSION="$arg" ;;
    esac
done

bold()  { printf '\033[1m%s\033[0m' "$1"; }
dim()   { printf '\033[2m%s\033[0m' "$1"; }
red()   { printf '\033[31m%s\033[0m' "$1"; }
green() { printf '\033[32m%s\033[0m' "$1"; }
cyan()  { printf '\033[36m%s\033[0m' "$1"; }

step() { echo; echo "$(cyan "==>") $(bold "$1")"; }
ok()   { echo "    $(green "✔") $1"; }
fail() { echo "    $(red "✗") $1" >&2; exit 1; }

# ─────────────────────────────────────────────────────────────────────────────
# Pre-flight checks
# ─────────────────────────────────────────────────────────────────────────────

step "Pre-flight"

if ! command -v composer >/dev/null; then fail "composer not found in PATH"; fi
if ! command -v git >/dev/null;      then fail "git not found in PATH";      fi
if ! command -v php >/dev/null;      then fail "php not found in PATH";      fi

if [ -n "$(git status --porcelain)" ]; then
    fail "working tree is not clean — commit or stash first"
fi
ok "working tree clean"

current_branch="$(git rev-parse --abbrev-ref HEAD)"
if [ "$current_branch" != "main" ]; then
    fail "not on main (current branch: $current_branch)"
fi
ok "on main"

git fetch --quiet origin main
local_sha="$(git rev-parse HEAD)"
remote_sha="$(git rev-parse origin/main)"
if [ "$local_sha" != "$remote_sha" ]; then
    fail "local main is not in sync with origin/main"
fi
ok "main is in sync with origin"

# Resolve the version to tag.
constant_version="$(php -r "require '${VERSION_FILE}'; echo PoliPage\\Internal\\Version::VERSION;")"
target_version="${EXPECTED_VERSION:-$constant_version}"
if [ "$target_version" != "$constant_version" ]; then
    fail "VERSION constant ($constant_version) does not match requested tag ($target_version)"
fi
if ! [[ "$target_version" =~ ^[0-9]+\.[0-9]+\.[0-9]+(-[A-Za-z0-9.-]+)?$ ]]; then
    fail "VERSION ($target_version) is not SemVer-shaped"
fi
ok "VERSION = $target_version"

tag="v$target_version"
if git rev-parse "refs/tags/$tag" >/dev/null 2>&1; then
    fail "tag $tag already exists locally"
fi
if git ls-remote --tags origin "$tag" | grep -q "$tag"; then
    fail "tag $tag already exists on origin"
fi
ok "tag $tag is fresh"

if ! grep -q "## \\[$target_version\\]" CHANGELOG.md; then
    fail "no [$target_version] entry in CHANGELOG.md"
fi
ok "CHANGELOG.md has a [$target_version] entry"

# ─────────────────────────────────────────────────────────────────────────────
# CI gate
# ─────────────────────────────────────────────────────────────────────────────

step "CI gate"

composer validate --strict
ok "composer.json valid"

composer install --prefer-dist --no-progress --no-interaction --no-scripts
ok "deps installed"

composer audit
ok "no advisories"

vendor/bin/php-cs-fixer fix --dry-run --diff
ok "lint clean"

vendor/bin/phpstan analyse --no-progress
ok "static analysis clean"

vendor/bin/phpunit --testsuite=unit
ok "unit tests passing"

# ─────────────────────────────────────────────────────────────────────────────
# Tag
# ─────────────────────────────────────────────────────────────────────────────

step "Tag"

if [ "$DRY_RUN" -eq 1 ]; then
    echo "$(dim "DRY-RUN: would create annotated tag $tag — skipping.")"
    exit 0
fi

printf '    Create annotated tag %s? [y/N] ' "$(bold "$tag")"
read -r confirm
case "$confirm" in
    y|Y|yes|YES) ;;
    *) echo "    $(dim "aborted by user — no tag created.")"; exit 0 ;;
esac

git tag -a "$tag" -m "Release $tag"
ok "tag $tag created locally"

echo
echo "$(bold "Next:") push the tag when you're ready:"
echo "    $(cyan "git push origin $tag")"
echo
echo "Packagist's GitHub webhook indexes the new version within ~seconds."
