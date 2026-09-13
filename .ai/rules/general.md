---
paths:
    - '**/*'
    - package.json
---

# General

## Run composer preflight before finishing changes — CI checks everything, not just touched files

Before declaring a change done (and always before a deploy), run `composer preflight`: fixers (Pint, ESLint, Prettier --write) then the exact CI aggregate (`ci:check` = prettier format:check, vue-tsc, PHPStan level 7, full Pest suite). Running targeted single-file tools instead is how CI broke on 2026-08-27: Prettier drift on files the session never touched and 39 accumulated PHPStan errors, invisible because only `pint --dirty` and per-file prettier ran locally. Vendored registry JSONs are .prettierignored deliberately — never reformat them. Deploys additionally require both CI jobs green (`tests-mysql` is the cross-DB gate) and deploy the CI-approved commit.

## Keep the pnpm pin below v11 — pnpm 12 breaks Dependabot and old launchers

`packageManager` stays at `pnpm@10.28.0`. Bumping it to 12.4.1 was tried on 2026-09-13 and reverted: pnpm 12 ships itself as a platform binary (`@pnpm/exe.*`, visible as ~158 extra lockfile lines), and Dependabot's npm ecosystem cannot fetch that inside its network-restricted container — every JS dependency failed with `unknown_error` after `Downloading the pnpm 12.4.1 binary for linux-x64...`. Older launchers cannot start it either: Ubuntu's corepack 0.24 looks for the `bin/pnpm.cjs` entry point pnpm dropped after v10, so the demo node and any contributor on a distro corepack fail with `Cannot find module …/bin/pnpm.cjs`.

Launchers are backward-compatible but not forward-compatible: a newer pnpm runs an older pin fine (the node's standalone pnpm 12 binary runs 10.28.0), the reverse fails. So pin low and let everyone's launcher be current. Before raising the pin again, check that Dependabot's npm updates still succeed.

Install JS deps with pnpm, never npm — the lockfile is pnpm's. CI and `composer setup` install the launcher with `npm install --global pnpm`; the demo node uses pnpm's standalone binary under /usr/local. Do not reintroduce `corepack enable` anywhere.
