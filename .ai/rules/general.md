---
paths:
    - '**/*'
---

# General

## Run composer preflight before finishing changes — CI checks everything, not just touched files

Before declaring a change done (and always before a deploy), run `composer preflight`: fixers (Pint, ESLint, Prettier --write) then the exact CI aggregate (`ci:check` = prettier format:check, vue-tsc, PHPStan level 7, full Pest suite). Running targeted single-file tools instead is how CI broke on 2026-08-27: Prettier drift on files the session never touched and 39 accumulated PHPStan errors, invisible because only `pint --dirty` and per-file prettier ran locally. Vendored registry JSONs are .prettierignored deliberately — never reformat them. Deploys additionally require both CI jobs green (`tests-mysql` is the cross-DB gate) and deploy the CI-approved commit.
