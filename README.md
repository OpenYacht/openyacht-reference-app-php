# OpenYacht Reference App (PHP)

The reference implementation of the [OpenYacht federation protocol](https://github.com/OpenYacht/protocol): a complete Laravel node that acts as both **authority** (serving its own listings to partners) and **consumer** (syncing, curating, and displaying partner listings).

This app is reference material first, installable product second. It exists so a developer — or an AI coding agent — can see a conventional, working federation node and lift the patterns into their own system. Every architectural choice serves readability of the protocol mechanics: thin controllers, explicit service classes (`app/Services/Federation/`), spec citations in docblocks at the point of implementation, and no bespoke abstractions where a framework convention exists.

## What it implements

**Authority role**
- Own listings with the full wire schema (specifications, descriptions in the restricted HTML subset, features, compliance), validated at data entry against the vendored registries — builder slugs, category slugs — exactly as the spec requires
- Ed25519 request signing and verification (passes the spec's signing test vectors byte-for-byte), key rotation (`openyacht:key:rotate` — routine, emergency, and post-overlap retirement)
- `/.well-known/openyacht`, capabilities, health, and the listings endpoints with keyset cursors, `updated_since` incremental sync, tombstones, and per-partner field-group gating
- Listing lifecycle (`draft → active ⇄ under_offer → sold | withdrawn`) with canonical URIs minted once and terminal listings dereferenceable through the retention window

**Consumer role**
- Trust-on-first-use partner establishment, signed sync (`openyacht:sync`, scheduled hourly), verbatim copies stored with provenance and never re-served
- Curated imports: chosen copies become displayable yachts with locally generated WebP renditions (srcset widths plus a cropped hero) from the single wire image
- Tolerant of schema drift: unknown fields ignored, unreadable values degrade to null — never an exception (see `SchemaToleranceTest`)

**Application shell**
- Roles and permissions (permission-based authorization throughout — roles are UI), activity log, translation-ready strings
- Unified read API (`/api/v1/yachts`) carrying own + imported inventory in the wire-schema shape, with hashed API keys, scopes, and per-key rate limiting — so the node can feed a public website
- Listing pages with shared card grid and faceted filtering (search, location, category, size)

## Not yet implemented

The optional protocol features this node's capabilities endpoint honestly advertises as `false`:

- **Charter listings** — the wire's `charter` block (rates, operating areas from the destination registry, crew, guest capacities in action). Sale listings correctly carry `charter: null`, but no charter listing has flowed through this node yet.
- **Subscriptions (push)** — signed webhook delivery of changes (`POST /openyacht/v1/subscriptions`). Polling `updated_since` is the mandatory baseline and is fully implemented; push is the optional layer on top.

Also pending: an import connector for an incumbent feed, per-partner sharing-rules UI, an installation wizard, and signed URLs for the `media_original` field group.

## Requirements

- PHP 8.4+ with the `sodium` extension (Ed25519)
- Composer, Node 22+, and a package manager (`pnpm` recommended)
- SQLite (zero-config default) — MySQL 8 / MariaDB fully supported and CI-enforced

## Setup

```bash
composer setup            # install, .env, key, migrate, build
php artisan openyacht:install   # mint the node UUID + initial federation keypair
```

Then set the node's identity in `.env`:

| Variable | Purpose |
|---|---|
| `OPENYACHT_DOMAIN` | The node's identity domain — permanent in practice, choose deliberately |
| `OPENYACHT_NODE_NAME` | Display name published in the well-known document |
| `OPENYACHT_WEBSITE` | Public website URL |
| `OPENYACHT_ATTRIBUTION_TEXT` | Attribution partners must display |
| `OPENYACHT_MEDIA_DISK` | Disk for imported-media renditions (`public` locally; S3/R2 via standard Laravel disks) |
| `OPENYACHT_MAP_PROVIDER` / `OPENYACHT_MAPBOX_TOKEN` | Coordinate-picker map: OpenStreetMap needs no key; Mapbox upgrades tiles, 3D globe, and geocoding |

Production needs the scheduler (hourly sync) and a queue worker (media imports).

## Tests are the conformance story

The Pest suite is grouped by the spec's conformance IDs — the test run *is* the self-certification:

```bash
php artisan test                                    # SQLite
php artisan test --configuration=phpunit.mysql.xml  # identical suite on MySQL
php artisan test --group=FP-7                       # a single conformance ID
```

Cross-database parity is enforced: both lanes run in CI, and engine-specific traps are documented in `.ai/rules/`.

## Vendored registries

`resources/registry/` holds vendored copies of the shared-vocabulary registries (`builders.json`, `categories.json`, `destinations.json`) from the protocol repository. They are validation lists, updated out-of-band — never fetched at request time.

## License

MIT.
