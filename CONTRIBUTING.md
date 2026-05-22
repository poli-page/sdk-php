# Contributing to `poli-page/sdk`

Thanks for your interest. A few short rules:

## Working method

We use **TDD**: write a failing test first, then the minimum code to pass.
See `CLAUDE.md` at the repo root for the full methodology and the
`sdk-engineering-guide.md` upstream for cross-SDK policy.

## Commit messages

[Conventional Commits](https://www.conventionalcommits.org/):
`feat:`, `fix:`, `docs:`, `refactor:`, `test:`, `chore:`.

## Local development

```bash
composer install

composer test              # PHPUnit unit suite
composer analyse           # PHPStan max + strict-rules
composer lint              # PHP-CS-Fixer dry-run with diffs
composer format            # PHP-CS-Fixer auto-fix
composer audit             # composer security advisories
composer ci                # lint + analyse + audit + test (the CI gate)
```

The SDK targets PHP 8.3, 8.4, and 8.5; CI runs the full matrix on Linux
plus one job each on macOS and Windows for the newest version.

## Integration tests

Integration tests hit the develop API. They are excluded by default
(grouped under `integration`); run with:

```bash
export POLI_PAGE_API_KEY=pp_test_...
composer test:integration
```

The suite skips itself cleanly when `POLI_PAGE_API_KEY` is unset.

## Running the demo

```bash
composer install
php examples/demo.php
```

The first run prompts for a `pp_test_*` key and saves it to `.env`.
Subsequent runs are silent. The demo walks every public method in the
order called out by the cross-SDK porter checklist; see
`examples/demo.php` for the annotated source.

## Releasing

Releases are **manual** and gated by `scripts/release.sh`. Packagist
auto-publishes on tag push via its GitHub webhook — there is no
separate publish step. The script runs every CI gate locally, prompts
for confirmation, and creates a local annotated tag; pushing the tag
is left to you so you stay in the loop.

1. Bump `VERSION` in `src/Internal/Version.php`.
2. Move `[Unreleased]` → `[X.Y.Z] - YYYY-MM-DD` in `CHANGELOG.md`.
3. If a MAJOR bump, add a section to `MIGRATION.md`.
4. Commit `chore(release): X.Y.Z` on a clean main branch and push.
5. Run the release script (dry-run first if you want):
   ```bash
   ./scripts/release.sh --dry-run    # everything except the tag
   ./scripts/release.sh              # interactive
   ```
6. Push the tag when ready: `git push origin vX.Y.Z`.
7. Packagist's webhook indexes the new version within seconds.

### Local pre-push hook

`scripts/install-hooks.sh` installs a `.git/hooks/pre-push` that runs
the same CI gate before every push, so you can't accidentally land a
broken main:

```bash
./scripts/install-hooks.sh
# to skip in an emergency (doc-only changes etc.):
SKIP_PRE_PUSH=1 git push
```

### Stable vs. prerelease channels

Packagist uses Composer's standard stability flags for prereleases —
there is no separate registry tag like npm's `next`. Tag prereleases
as `vX.Y.Z-rc1`, `vX.Y.Z-beta1`, etc.; users opt in by version range:

```bash
composer require poli-page/sdk:^2.0@beta
composer require poli-page/sdk:2.0.0-rc1     # specific prerelease
```

Stable and prerelease tags must never point at the same commit — once a
prerelease is promoted, the next prerelease starts a new pre-suffix
sequence.
