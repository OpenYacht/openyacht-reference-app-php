---
paths:
    - 'resources/js/**'
---

# Js

## Run wayfinder:generate with --with-form

`vite.config.ts` configures the Wayfinder plugin with `formVariants: true`, but `php artisan wayfinder:generate` defaults to WITHOUT them. Running it bare strips `.form` off every generated route and breaks `vue-tsc` in ~12 files you never touched (auth pages, ManageTwoFactor, Profile, Security, federation/partners) with `Property 'form' does not exist`. Always run `php artisan wayfinder:generate --with-form`, or just let `pnpm build`/`pnpm dev` regenerate through the plugin. `resources/js/routes` and `resources/js/actions` are gitignored and regenerated at build time, so the damage is local-only — but it looks like a mystery regression until you spot the cause.

## OpenYacht branding: use the vendored brand assets, never recolour the flags

Branding is decided project-wide (openyacht repo, docs/branding.md; masters in the openyacht-org repo, canonical URLs at https://openyacht.org/brand/). The "Code Hoist" mark (Oscar over Yankee signal flags) is inlined in AppLogoIcon.vue — the halyard swaps ink/ivory with the theme, the flag colours are the letters and must NEVER be recoloured or run through currentColor/theme tokens. Centred vertical contexts (login, splash) use the vendored stacked lockups in public/brand/ (light + reversed pair via dark: classes). Minimum mark height 24px; don't reset the OPENYACHT wordmark in live type — use the lockup SVGs. Prose spells "OpenYacht", one word, capital O and Y.
