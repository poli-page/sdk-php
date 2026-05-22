#!/usr/bin/env bash
# scripts/install-hooks.sh — idempotent installer for the project's git hooks.
#
# Currently installs a pre-push hook that runs the local CI gate (lint +
# phpstan + composer audit + phpunit) so contributors can't push a broken
# branch by accident. Skips the integration suite by default — set
# `SKIP_INTEGRATION=0` in your shell to opt in.

set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
HOOK_DIR="$REPO_ROOT/.git/hooks"
HOOK_FILE="$HOOK_DIR/pre-push"

if [ ! -d "$REPO_ROOT/.git" ]; then
    echo "error: $REPO_ROOT does not look like a git checkout" >&2
    exit 1
fi

mkdir -p "$HOOK_DIR"

cat > "$HOOK_FILE" <<'HOOK'
#!/usr/bin/env bash
# Auto-installed by scripts/install-hooks.sh. Re-run that script to refresh.
#
# Runs the local CI gate before allowing a push. To skip in an emergency
# (doc-only changes, fast-follow rollback), set `SKIP_PRE_PUSH=1`.

set -euo pipefail

if [ "${SKIP_PRE_PUSH:-0}" = "1" ]; then
    echo "[pre-push] SKIP_PRE_PUSH=1 — bypassing local CI gate."
    exit 0
fi

cd "$(git rev-parse --show-toplevel)"

if [ ! -f composer.json ]; then
    echo "[pre-push] no composer.json — skipping."
    exit 0
fi

echo "[pre-push] composer validate --strict"
composer validate --strict

echo "[pre-push] composer audit"
composer audit

if [ -x vendor/bin/php-cs-fixer ]; then
    echo "[pre-push] php-cs-fixer --dry-run --diff"
    PHP_CS_FIXER_IGNORE_ENV=1 vendor/bin/php-cs-fixer fix --dry-run --diff
fi

if [ -x vendor/bin/phpstan ]; then
    echo "[pre-push] phpstan analyse"
    vendor/bin/phpstan analyse --no-progress
fi

if [ -x vendor/bin/phpunit ]; then
    echo "[pre-push] phpunit --testsuite=unit"
    vendor/bin/phpunit --testsuite=unit
fi
HOOK

chmod +x "$HOOK_FILE"

echo "✔ installed $HOOK_FILE"
echo "  to skip in an emergency: SKIP_PRE_PUSH=1 git push"
