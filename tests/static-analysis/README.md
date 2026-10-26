# Static-analysis tests

These PHP files are **intentionally invalid** — PHPStan must reject them.
They live outside the main test suite (PHPUnit never runs them) and serve
as belt-and-suspenders for type-system contracts the runtime already
enforces (e.g. `RenderInput` has no `version` parameter, which PHP
rejects at runtime as an unknown named parameter).

Run locally:

```bash
vendor/bin/phpstan analyse tests/static-analysis/should_fail --error-format=raw 2>&1 \
    | grep -q "Found" || (echo "PHPStan should have rejected these files" && exit 1)
```

CI runs the equivalent check; the gate fails if any file in
`should_fail/` ever type-checks cleanly. When that happens, either:

1. The contract was weakened by a real code change —
   restore the invariant.
2. PHPStan got smarter and now infers the rejection statically — move
   the file out of `should_fail/` and into a regular unit test.
