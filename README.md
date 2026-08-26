# OpenYacht Reference App (PHP)

The reference implementation of the [OpenYacht federation protocol](https://github.com/OpenYacht/protocol): a complete Laravel node that acts as both **authority** (serving its own listings to partners) and **consumer** (syncing, curating, and displaying partner listings).

This app is reference material first, installable product second. It exists so a developer — or an AI coding agent — can see a conventional, working federation node and lift the patterns into their own system. Every architectural choice serves readability of the protocol mechanics: thin controllers, explicit service classes (`app/Services/Federation/`), spec citations in docblocks at the point of implementation, and no bespoke abstractions where a framework convention exists.

## What it implements

**Authority role**
- Own listings with the full wire schema (specifications, descriptions in the restricted HTML subset, features, compliance), validated at data entry against the vendored registries — builder slugs, category slugs — exactly as the spec requires
- **Both listing types, structurally separated**: sale and charter listings live in separate tables (the table *is* the type, which is how the type stays immutable like the canonical UUID), authored on separate screens, and served through one unioned feed. Charter listings carry the schema's type conditional — `listing.price: null`, a shape-complete `charter` block (rates by season, operating areas validated against the vendored destination registry, base ports, crew) — with rates under the `pricing` field group and crew distributed only while a charter-manager/captain attestation is on record (LS-15)
- Ed25519 request signing and verification (passes the spec's signing test vectors byte-for-byte), key rotation (`openyacht:key:rotate` — routine, emergency, and post-overlap retirement)
- `/.well-known/openyacht`, capabilities, health, and the listings endpoints with keyset cursors, `updated_since` incremental sync, tombstones, and per-partner field-group gating
- Listing lifecycle (`draft → active ⇄ under_offer → sold | withdrawn`) with canonical URIs minted once and terminal listings dereferenceable through the retention window
- **Per-listing, per-partner sharing**: each listing's audience is everyone, selected partners/groups, or no one; per-partner field-group grants re-gate payloads server-side. Every visibility change lands in an append-only event log the feed replays against any `updated_since` watermark — unsharing surfaces as a tombstone indistinguishable from a real withdrawal, re-sharing as a normal update, and a grants change resends re-gated payloads on the partner's next poll. Partner groups are the audience shorthand; membership changes replay through the same log without touching any listing

**Consumer role**
- Trust-on-first-use partner establishment, signed sync (`openyacht:sync`, scheduled hourly), verbatim copies stored with provenance and never re-served
- Curated imports: chosen copies become displayable yachts with locally generated WebP renditions (srcset widths plus a cropped hero) from the single wire image
- Tolerant of schema drift: unknown fields ignored, unreadable values degrade to null — never an exception (see `SchemaToleranceTest`)
- Node-directory discovery (FP-16): a directory admin page with the vendored advisory phonebook (canonical-URL-only refresh, searchable, add-as-partner through the exact same TOFU path as a hand-typed domain) and this node's own listing consent — findability status plus the signed list/delist/amend requests (also via `openyacht:listing-token`)

**Application shell**
- Roles and permissions (permission-based authorization throughout — roles are UI), activity log, translation-ready strings
- Unified read API (`/api/v1/yachts`) carrying own + imported inventory in the wire-schema shape, with hashed API keys, scopes, and per-key rate limiting — so the node can feed a public website
- Listing pages with shared card grid and faceted filtering (search, location, category, size)

## Not yet implemented

The optional protocol features this node's capabilities endpoint honestly advertises as `false`:

- **Subscriptions (push)** — signed webhook delivery of changes (`POST /openyacht/v1/subscriptions`). Polling `updated_since` is the mandatory baseline and is fully implemented; push is the optional layer on top.

Also pending: an import connector for an incumbent feed, an installation wizard, and signed URLs for the `media_original` field group.

## Requirements

- PHP 8.4+ with the `sodium` extension (Ed25519)
- Composer, Node 22+, and a package manager (`pnpm` recommended)
- SQLite (zero-config default) — MySQL 8 / MariaDB fully supported and CI-enforced

## Setup

```bash
composer setup            # install, .env, key, migrate, build
php artisan openyacht:install   # mint the node UUID + initial federation keypair
php artisan openyacht:create-user   # create the first user — there is no self-registration
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

Email (password resets, federation alerts) defaults to the `log` mailer. For real delivery set `MAIL_MAILER=brevo` with a `BREVO_API_KEY` (Brevo's HTTP API — no SMTP credentials needed) and a real `MAIL_FROM_ADDRESS`; any other Laravel mail transport works the same way. Federation events needing a human — an unknown node introducing itself (FP-13) and a partner's node UUID changing (FP-11) — are emailed to users holding the *Receive federation notifications* permission (super admins by default; tune it in the roles matrix). After upgrades that add permissions, re-run `php artisan db:seed --class=RoleSeeder` — it is idempotent and keeps super_admin holding every permission without touching a tuned matrix.

## Deployment

Zero-downtime deploys via [Deployer](https://deployer.org) — the committed `deploy.php` is the whole recipe, and this section is the server half. Any small VPS works; a 2-core / 4 GB instance (e.g. Hetzner's entry tier) runs the app, its queue worker, and MySQL comfortably. Every node is one `host()` stanza with instance-scoped names (deploy path, database, worker program), so a second node — on the same server or another — is one more stanza, not a second recipe.

Provision once (Ubuntu 24.04, as root — creates the unprivileged `deployer` user the recipe connects as):

```bash
adduser --disabled-password deployer && su - deployer -c 'mkdir -p ~/.ssh' \
  && cp ~/.ssh/authorized_keys /home/deployer/.ssh/ && chown -R deployer: /home/deployer/.ssh

add-apt-repository -y ppa:ondrej/php
apt install -y nginx certbot python3-certbot-nginx mysql-server supervisor git unzip \
  php8.4-fpm php8.4-cli php8.4-mysql php8.4-sqlite3 php8.4-gd php8.4-curl \
  php8.4-mbstring php8.4-xml php8.4-zip php8.4-intl php8.4-bcmath
php8.4 -m | grep -q sodium || echo 'MISSING: sodium (required for Ed25519 signing)'

curl -sS https://getcomposer.org/installer | php8.4 -- --install-dir=/usr/local/bin --filename=composer
curl -fsSL https://deb.nodesource.com/setup_22.x | bash - && apt install -y nodejs
corepack enable && corepack prepare pnpm@latest --activate
```

Point nginx at `/home/deployer/openyacht-test/current/public` (standard Laravel vhost, `client_max_body_size 32m` for media uploads), issue TLS with certbot — the identity domain must serve real TLS; partners verify it — and give the queue worker a supervisor program and the scheduler its cron. Both are load-bearing: media imports, federation alert emails, and auto-publish all ride them.

```ini
; /etc/supervisor/conf.d/openyacht-test-worker.conf
[program:openyacht-test-worker]
command=php8.4 /home/deployer/openyacht-test/current/artisan queue:work --sleep=3 --tries=3 --max-time=3600
user=deployer
autostart=true
autorestart=true
```

```cron
* * * * * cd /home/deployer/openyacht-test/current && php8.4 artisan schedule:run >> /dev/null 2>&1
```

Then, from a checkout: `DEPLOY_HOST=your.domain vendor/bin/dep deploy test`. The first run stops at the missing shared `.env` — create it (`APP_KEY` via `php artisan key:generate --show`, database credentials, the `OPENYACHT_*` identity variables, Brevo mail), deploy again, and inside `current/` run `php artisan db:seed --class=RoleSeeder --force`, `php artisan openyacht:install`, and `php artisan openyacht:create-user` once (deploys migrate but never seed — the role matrix comes from the seeder). Every later deploy is the single `dep deploy` command: it builds assets on the server, migrates, restarts the queue worker, and swaps the `current` symlink atomically.

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
