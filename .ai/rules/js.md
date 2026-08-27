---
paths:
  - 'resources/js/**'
---

# Js

## Run wayfinder:generate with --with-form
`vite.config.ts` configures the Wayfinder plugin with `formVariants: true`, but `php artisan wayfinder:generate` defaults to WITHOUT them. Running it bare strips `.form` off every generated route and breaks `vue-tsc` in ~12 files you never touched (auth pages, ManageTwoFactor, Profile, Security, federation/partners) with `Property 'form' does not exist`. Always run `php artisan wayfinder:generate --with-form`, or just let `pnpm build`/`pnpm dev` regenerate through the plugin. `resources/js/routes` and `resources/js/actions` are gitignored and regenerated at build time, so the damage is local-only — but it looks like a mystery regression until you spot the cause.
