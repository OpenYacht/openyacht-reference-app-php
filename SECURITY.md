# Security policy

This application signs and verifies federation requests with Ed25519, holds the trust material for every partner node, and serves listings to machines it does not control. Security reports are wanted here, not tolerated.

## Reporting a vulnerability

Report it privately through GitHub: **[Security → Report a vulnerability](https://github.com/OpenYacht/openyacht-reference-app-php/security/advisories/new)**. Please do not open a public issue, and please do not test against a node you do not operate.

Useful reports say which commit, what an attacker gains, and the smallest reproduction you have. The ideal report is a failing Pest test: the suite is grouped by the spec's conformance IDs, so a test named for the behaviour it breaks arrives as the fix and the regression guard at once.

This is a small project — reports are acknowledged as soon as a maintainer sees them, not on a clock. Confirmed issues are fixed before disclosure, and credit goes to the reporter unless they ask otherwise.

## In scope

Anything that lets a caller cross a boundary the protocol draws:

- forging, replaying, or stripping a request signature; bypassing the timestamp window or key rotation
- reading a listing, field group, or partner's data the sharing rules do not grant — the per-partner field-group gating in particular
- authenticating to the data API without a valid key, or escaping a key's scopes or rate limit
- privilege escalation through the roles and permissions matrix, or any path that authorizes by role instead of permission
- the usual web classes in application code: injection, XSS through the restricted HTML subset, SSRF through partner-supplied URLs during sync or media import, deserialization

## Not in scope

- **The public API documentation.** `/docs/api` and `/docs/api.json` are world-readable by design — the API key, its scopes, and its rate limit are the access boundary; the documentation is not. The spec contains only the two public `/api/v1/yachts` paths.
- **Defaults that are development defaults.** `.env.example` ships `APP_DEBUG=true` and the `log` mailer because it is a local starting point. Deploying it verbatim is an operator error, not a vulnerability.
- **A flaw in the protocol itself** rather than in this implementation. Those belong in the [protocol repository](https://github.com/OpenYacht/protocol) — privately first if exploitable — because every implementation shares them.
- Findings from automated scanners with no demonstrated impact, and missing hardening headers on a node you do not operate.

## Supported versions

This is reference material: the `main` branch is what is supported. There are no backported security releases — a fix lands on `main` and self-hosting operators deploy it.
