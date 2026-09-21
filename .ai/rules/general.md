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

## Changing a media disk or its public URL is invisible to the app — notify consumers, and stamp own listings

Both media disks are plain Laravel disks (`OPENYACHT_MEDIA_DISK` for imported renditions, `MEDIA_DISK` for own listing media via medialibrary), so moving them is an operational change no model event sees. Order: copy files to the new disk under the same paths, switch the variable, rebuild the config cache, verify, and only then remove the old files. Imported media stores a path only and follows the config at once, but nothing notifies consumers of the move: run `php artisan openyacht:notify-change` after it, or a statically built consumer keeps the old URLs until rebuilt — then breaks when the old files go. Own media is `php artisan openyacht:move-media <disk>` (OpenYachtMoveMedia): medialibrary rows store their own `disk`/`conversions_disk`, and own media URLs are on the wire, so the command repoints the rows and touches `federation_updated_at` on exactly the served listings (same reasoning as the migrations rule; the model's hooks then notify webhooks and push subscribers). Do not hand-roll that move in tinker. The S3 driver needs `league/flysystem-aws-s3-v3`; the `s3` disk config alone is not enough (it sat configured but uninstalled until 2026-09-21, while docs called the disk swappable). For non-AWS S3 stores: endpoint without the bucket name, `AWS_URL` with the scheme.
