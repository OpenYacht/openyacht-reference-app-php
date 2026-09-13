# Contributing

This repository is reference material first, installable product second. The bar for a change is not "does it work" but **"does it make the protocol mechanics easier to read"** — a developer, or an AI coding agent, should be able to open any file here and lift the pattern into their own system. Cleverness is a defect.

## Where a change belongs

- **The protocol is normative and lives elsewhere.** The spec, schemas, registries, and conformance checklist are amended by PR in the [protocol repository](https://github.com/OpenYacht/protocol). If this app disagrees with the spec, this app is wrong — that is a bug report here. If the spec is ambiguous or self-contradictory, that is spec feedback there.
- **This repository implements it.** Conformance gaps, bugs, and the application shell around them are in scope here.
- Vulnerabilities go through [SECURITY.md](SECURITY.md), privately — never a public issue.

## House rules

These are not preferences; they are the reasons the code reads the way it does. The full set, scoped by path, is in [`.ai/rules/`](.ai/rules/index.md) — read the rule files whose globs cover what you are touching before you write code.

- **Cite the spec at the point of implementation.** A federation behaviour carries its normative reference in the docblock (`federation-protocol.md §Request Signing`) so a reader lands in the right text from any file.
- **Every federation behaviour gets a test grouped by its conformance ID** — `->group('FP-7')`, `->group('API-3')`, `->group('LS-8')`. The suite is the self-certification; a behaviour with no conformance group is not finished.
- **Boring, idiomatic Laravel.** Thin controllers, explicit service classes, no bespoke abstraction where a framework convention exists.
- **Authorize by permission, never by role.** Roles are a UI over a permission matrix; `hasRole()` in new code blocks a merge.
- **Every user-facing string goes through a translation key** (`lang/en/*.php`, `__()` server-side, translated props into Inertia pages). English-only today; the discipline is what makes more locales a later milestone instead of a rewrite.
- **Migrations must work on SQLite, MySQL 8, and MariaDB.** SQLite is the zero-config default and MySQL is CI-enforced. Watch `->change()`, enum alterations, JSON defaults, and DB-specific index syntax — the known traps are written up in `.ai/rules/migrations.md`.
- **Copies of partner listings are never re-served.** They live in their own tables; `/listings` output is own listings only.
- Vendored registry JSON under `resources/registry/` is byte-identical to the protocol repo and is `.prettierignore`d deliberately. Never reformat it; update it from upstream.

## Before you open a pull request

```bash
composer preflight
```

That is the CI-equivalent gate: fixers first (Pint, ESLint, Prettier), then the exact checks CI runs — format check, `vue-tsc`, PHPStan level 7, and the full Pest suite. Run it whole. Targeted per-file linting is how CI has broken before: drift accumulates in files your change never touched.

If your change touches schema or queries, run the MySQL lane too — CI will, and engine divergences must fail there rather than in someone's production node:

```bash
vendor/bin/pest --configuration=phpunit.mysql.xml
```

Requirements are PHP 8.4+ with the `sodium` extension, Node 22+, and pnpm; `composer setup` does the rest.

## Pull requests

- One concern per PR. Explain what changed and why the protocol needed it.
- Commit subjects are imperative and say the effect, not the mechanism — "Refuse self-partnership and allow removing a partner nothing came from", not "update PartnerController".
- New user-facing behaviour comes with its conformance-grouped test; a bug fix comes with the test that failed before it.
- Documentation files are not created speculatively. The README is the operator's guide; `.ai/rules/` is where durable, path-scoped decisions go.

## Licensing of contributions

By contributing you agree your contribution is licensed under [AGPL-3.0-only](LICENSE), the license covering this repository, and that you have the right to license it.
