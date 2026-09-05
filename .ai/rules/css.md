---
paths:
    - 'resources/css/**'
---

# Css

## Semantic colours map to the brand ramps — and Nuxt UI needs @theme static

Nuxt UI aliases (vite.config.ts) map primary/info → signal-blue, error → signal-red, warning → signal-yellow, success → starboard (a complementary green; the ICS locker has no green). The ramps in resources/css/app.css anchor each brand hex VERBATIM (signal-blue-900, signal-red-700, signal-yellow-400) — never re-derive those values. The ramps MUST stay inside `@theme static`: Nuxt UI consumes them at runtime via var(--color-signal-blue-500) etc., which Tailwind cannot see, so a plain @theme tree-shakes the variables and every semantic colour silently collapses to unstyled. Never hardcode Tailwind colour-family classes (bg-blue-500…) — always the semantic aliases.
