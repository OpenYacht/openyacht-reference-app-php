# OpenYacht Reference App (PHP)

The reference implementation of the [OpenYacht federation protocol](https://github.com/OpenYacht/protocol): a complete Laravel node that acts as both **authority** (serving its own listings to partners) and **consumer** (syncing, curating, and displaying partner listings).

This app is reference material first, installable product second. It exists so a developer — or an AI coding agent — can see a conventional, working federation node and lift the patterns into their own system. Every architectural choice serves readability of the protocol mechanics: thin controllers, explicit service classes (`app/Services/Federation/`), spec citations in docblocks at the point of implementation, and no bespoke abstractions where a framework convention exists.

## What it implements

**Authority role**

- Own listings with the full wire schema (specifications, descriptions in the restricted HTML subset, features, compliance), validated at data entry against the vendored registries — builder slugs, category slugs — exactly as the spec requires
- **Both listing types, structurally separated**: sale and charter listings live in separate tables (the table _is_ the type, which is how the type stays immutable like the canonical UUID), authored on separate screens, and served through one unioned feed. Charter listings carry the schema's type conditional — `listing.price: null`, a shape-complete `charter` block (rates by season, operating areas validated against the vendored destination registry, base ports, crew) — with rates under the `pricing` field group and crew distributed only while a charter-manager/captain attestation is on record (LS-15)
- Ed25519 request signing and verification (passes the spec's signing test vectors byte-for-byte), key rotation (`openyacht:key:rotate` — routine, emergency, and post-overlap retirement)
- `/.well-known/openyacht`, capabilities, health, and the listings endpoints with keyset cursors, `updated_since` incremental sync, tombstones, and per-partner field-group gating
- Listing lifecycle (`draft → active ⇄ under_offer → sold | withdrawn`) with canonical URIs minted once and terminal listings dereferenceable through the retention window
- **Per-listing, per-partner sharing**: each listing's audience is everyone, selected partners/groups, or no one; per-partner field-group grants re-gate payloads server-side. Every visibility change lands in an append-only event log the feed replays against any `updated_since` watermark — unsharing surfaces as a tombstone indistinguishable from a real withdrawal, re-sharing as a normal update, and a grants change resends re-gated payloads on the partner's next poll. Partner groups are the audience shorthand; membership changes replay through the same log without touching any listing

**Consumer role**

- Trust-on-first-use partner establishment, signed sync (`openyacht:sync`, scheduled hourly), verbatim copies stored with provenance and never re-served
- Curated imports: chosen copies become displayable yachts with locally generated WebP renditions (srcset widths plus a cropped hero) from the single wire image
- Tolerant of schema drift: unknown fields ignored, unreadable values degrade to null — never an exception (see `SchemaToleranceTest`)
- Push subscriptions, both halves (API-10/API-11): partners register one signed HTTPS callback each and receive every change their feed would report — the listing or a tombstone, derived from the same `federation_updated_at` stamp and visibility events the feed uses — as a queued signed POST with exponential backoff for 24 hours; this node subscribes to partners the same way, deduplicates inbox deliveries on `(id, updated_at)`, and still reconciles subscribed partners with a daily poll
- Node-directory discovery (FP-16): a directory admin page with the vendored advisory phonebook (canonical-URL-only refresh, searchable, add-as-partner through the exact same TOFU path as a hand-typed domain) and this node's own listing consent — findability status plus the signed list/delist/amend requests (also via `openyacht:listing-token`)

**Application shell**

- Roles and permissions (permission-based authorization throughout — roles are UI), an activity log of admin and federation events — the audit trail (partnerships, shares, imports, withdrawals) kept as evidence while only the high-volume sync summaries are pruned on a retention window — translation-ready strings
- Unified read API (`/api/v1/yachts`) carrying own + imported inventory in the wire-schema shape, with hashed API keys, scopes, and per-key rate limiting — so the node can feed a public website
- Listing pages with shared card grid and faceted filtering (search, location, category, size)

## Not yet implemented

Pending: an import connector for an incumbent feed, an installation wizard, and signed URLs for the `media_original` field group.

## Requirements

- PHP 8.4+ with the `sodium` extension (Ed25519)
- Composer, Node 22+, and pnpm (`npm install --global pnpm`) — the `packageManager` pin in `package.json` decides the exact version from there, so any recent pnpm is a fine launcher. Do not launch it through a distribution `corepack` package: those lag badly (Ubuntu ships 0.24, which cannot start any pnpm past v10 — it looks for a `bin/pnpm.cjs` entry point that no longer exists).
- SQLite (zero-config default) — MySQL 8 / MariaDB fully supported and CI-enforced

## Setup

```bash
composer setup            # install, .env, key, migrate, build
php artisan openyacht:install   # seed roles, mint the node UUID + first federation keypair
php artisan openyacht:create-user   # create the first user — there is no self-registration
```

Then set the node's identity in `.env`:

| Variable                                            | Purpose                                                                                           |
| --------------------------------------------------- | ------------------------------------------------------------------------------------------------- |
| `OPENYACHT_DOMAIN`                                  | The node's identity domain — permanent in practice, choose deliberately                           |
| `OPENYACHT_NODE_NAME`                               | Display name published in the well-known document                                                 |
| `OPENYACHT_WEBSITE`                                 | Public website URL                                                                                |
| `OPENYACHT_ATTRIBUTION_TEXT`                        | Attribution partners must display                                                                 |
| `OPENYACHT_MEDIA_DISK`                              | Disk for imported-media renditions (`public` locally; S3/R2 via standard Laravel disks)           |
| `OPENYACHT_MAP_PROVIDER` / `OPENYACHT_MAPBOX_TOKEN` | Coordinate-picker map: OpenStreetMap needs no key; Mapbox upgrades tiles, 3D globe, and geocoding |

Production needs the scheduler (hourly sync) and a queue worker (media imports).

Email (password resets, federation alerts) is ordinary Laravel mail: it defaults to the `log` mailer, and any transport works — set `MAIL_MAILER` to `smtp`, `ses`, `postmark`, `resend`, or `sendmail` with the matching credentials and a real `MAIL_FROM_ADDRESS`. A Brevo HTTP-API transport is registered alongside them (`MAIL_MAILER=brevo` with a `BREVO_API_KEY`) as one example of a provider that needs no SMTP credentials; nothing in the app depends on it.

Federation events needing a human — an unknown node introducing itself (FP-13) and a partner's node UUID changing (FP-11) — are emailed to users holding the _Receive federation notifications_ permission (super admins by default; tune it in the roles matrix). After upgrades that add permissions, re-run `php artisan db:seed --class=RoleSeeder` — it is idempotent and keeps super_admin holding every permission without touching a tuned matrix.

## Deployment

Zero-downtime deploys via [Deployer](https://deployer.org) — the committed `deploy.php` is the whole recipe, and this section is the server half. Any small VPS works; a 2-core / 4 GB instance (e.g. Hetzner's entry tier) runs the app, its queue worker, and MySQL comfortably. Every node is one `host()` stanza with instance-scoped names (deploy path, database, worker program, FPM pool), so a second node — on the same server or another — is one more stanza, not a second recipe.

The recipe is written out for an instance named `openyacht-test` — the deploy path `~/openyacht-test`, the `openyacht-test` FPM pool and socket, the `openyacht-test-worker` supervisor program, the `openyacht_test` database. That is a worked example, not a requirement: pick your own instance name and replace `openyacht-test` with it throughout this section and in your `host()` stanza.

The block below targets **Ubuntu 26.04 LTS**, which carries PHP 8.5 and Node 22 in its own archive, so no third-party repositories are involved. On Ubuntu 24.04, add `add-apt-repository -y ppa:ondrej/php` first and read `php8.5` as `php8.4` throughout. The PPA is not an option on 26.04 — it publishes nothing for `resolute` — which is why the native packages are the better path there anyway.

Provision once, as root — this creates the unprivileged `deployer` user the recipe connects as:

```bash
# --gecos "" or adduser stops at an interactive Full Name prompt.
adduser --disabled-password --gecos "" deployer
su - deployer -c 'mkdir -p ~/.ssh && chmod 700 ~/.ssh'
cp ~/.ssh/authorized_keys /home/deployer/.ssh/ && chown -R deployer: /home/deployer/.ssh
chmod 600 /home/deployer/.ssh/authorized_keys

# nginx runs as www-data and must traverse the home directory to reach
# current/public. adduser creates it 0750, which serves permission errors forever.
chmod 755 /home/deployer

apt update && apt install -y nginx certbot python3-certbot-nginx mysql-server supervisor git unzip curl \
  redis-server \
  php8.5-fpm php8.5-cli php8.5-mysql php8.5-sqlite3 php8.5-gd php8.5-curl \
  php8.5-mbstring php8.5-xml php8.5-zip php8.5-intl php8.5-bcmath php8.5-redis \
  nodejs
php8.5 -m | grep -q sodium || echo 'MISSING: sodium (required for Ed25519 signing)'

curl -sS https://getcomposer.org/installer | php8.5 -- --install-dir=/usr/local/bin --filename=composer

# pnpm as a self-contained binary (it bundles its own Node), versioned in
# its own directory so an upgrade is one more unpack and a symlink swap.
PNPM_VERSION=12.4.1
mkdir -p /usr/local/lib/pnpm-$PNPM_VERSION
curl -fsSL https://github.com/pnpm/pnpm/releases/download/v$PNPM_VERSION/pnpm-linux-x64.tar.gz \
  | tar xz -C /usr/local/lib/pnpm-$PNPM_VERSION
ln -sfn /usr/local/lib/pnpm-$PNPM_VERSION/pnpm /usr/local/bin/pnpm
```

Do **not** add the `npm` package on 26.04: the archive ships npm 9.2.0, whose `node-gyp` dependency pulls an unsatisfiable `libssl-dev` chain and aborts the entire `apt install`. Nothing here needs it — the pnpm binary above is self-contained, and it reads the `packageManager` pin in `package.json` and runs exactly that version, so the server builds with the same pnpm as CI and the lockfile. That pin is load-bearing: without it every user resolves `pnpm@latest` independently and drifts ahead of the lockfile.

Do not route the build through the distribution's `corepack` shim instead. Ubuntu ships corepack 0.24, which launches pnpm through the `bin/pnpm.cjs` entry point pnpm dropped after v10 — any pnpm 11 or newer dies there with `Cannot find module …/bin/pnpm.cjs`, whether corepack resolved it from the pin or from `pnpm@latest`.

Redis is in that list because the application's defaults are deliberately dependency-free, not because they are the right production choice. Out of the box the cache, session, and queue drivers are all `database`, which keeps a fresh install to one moving part — but it means every request touches MySQL for its session, and the queue worker polls the `jobs` table every three seconds forever. On a production node, point all three at Redis in the shared `.env`:

```dotenv
CACHE_STORE=redis
SESSION_DRIVER=redis
QUEUE_CONNECTION=redis
REDIS_QUEUE_RETRY_AFTER=960
REDIS_CLIENT=phpredis
REDIS_HOST=127.0.0.1
REDIS_PORT=6379
```

The queue is where this matters most: the database driver adds up to three seconds of latency to every media import and federation notification and writes to MySQL continuously while idle. Nothing in the protocol depends on the choice, and `database` remains a valid configuration — a node that would rather not run Redis simply omits it and skips this block. Changing `SESSION_DRIVER` invalidates existing sessions, so switch it during a maintenance window rather than under load, and restart both the FPM pool and the queue worker afterwards so they pick up the new drivers. Restart the worker through supervisor (`supervisorctl restart <instance>-worker`), not `artisan queue:restart` — that command signals workers through the cache, and when the switch changes `CACHE_STORE` itself the running worker is still watching the old store and never sees the signal.

Give each instance its own FPM pool, running as `deployer`, so a second node gets its own pool and socket beside this one:

```ini
; /etc/php/8.5/fpm/pool.d/openyacht-test.conf
[openyacht-test]
user = deployer
group = deployer
listen = /run/php/php8.5-fpm-openyacht-test.sock
listen.owner = www-data
listen.group = www-data
listen.mode = 0660
pm = dynamic
pm.max_children = 12
pm.start_servers = 3
pm.min_spare_servers = 2
pm.max_spare_servers = 5
php_admin_value[upload_max_filesize] = 32M
php_admin_value[post_max_size] = 32M
```

Point nginx at `/home/deployer/openyacht-test/current/public` as a standard Laravel vhost and issue TLS with certbot — the identity domain must serve real TLS; partners verify it. Three details are not optional:

```nginx
client_max_body_size 32m;              # media uploads

location ~ /\.(?!well-known).* {       # the discovery document is the trust root
    deny all;
}

location ~ \.php$ {
    fastcgi_pass unix:/run/php/php8.5-fpm-openyacht-test.sock;
    include fastcgi_params;
    fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
    fastcgi_param DOCUMENT_ROOT $realpath_root;

    # The app's response headers (session + XSRF cookies) run to ~6 KB.
    # nginx's 4 KB default overflows and every authenticated page 502s,
    # while lean JSON routes keep working — so the node looks half-alive.
    fastcgi_buffer_size 32k;
    fastcgi_buffers 16 32k;
    fastcgi_busy_buffers_size 64k;
}
```

If the identity domain sits behind Cloudflare, note that Universal SSL covers only a single label of subdomain: `node.example.com` proxies fine, `node.sub.example.com` has no edge certificate and fails the TLS handshake outright. Either leave such a record DNS-only so the origin serves its own certificate, or choose a single-label subdomain. Certbot's HTTP-01 challenge itself passes through a proxied record without complaint.

Then the queue worker and the scheduler — both load-bearing, since media imports, federation alert emails, and acceptance-policy auto-publish all ride them:

```ini
; /etc/supervisor/conf.d/openyacht-test-worker.conf
[program:openyacht-test-worker]
command=php8.5 /home/deployer/openyacht-test/current/artisan queue:work --queue=notifications,default --sleep=3 --tries=3 --max-time=3600
user=deployer
autostart=true
autorestart=true
stopwaitsecs=3600
```

```cron
* * * * * cd /home/deployer/openyacht-test/current && php8.5 artisan schedule:run >> /dev/null 2>&1
```

Run `supervisorctl update` only after the first deploy has created `current/`, or the program restart-loops against a path that does not exist yet.

The `--queue=notifications,default` order matters: outbound change-notification webhooks are dispatched on the `notifications` queue so a consumer's deploy hook fires as the very next job, instead of waiting behind a backlog of minute-long media imports on `default`. A worker started without the flag still drains `default` only and never sends a webhook. Each endpoint under Webhooks in the admin can also carry a scheduled interval: the scheduler pings it whenever that long passes without any other notification, a freshness floor for consumers that build from data the node never sees change (exchange rates, partners' well-known documents). Nothing outside the node needs a cron for this.

The worker's default 60-second job timeout is far too short for a media import — one job downloads a yacht's whole gallery and renders several sizes of every image — so `ImportYachtMedia` declares its own (900 seconds); no `--timeout` flag is needed. What _is_ needed is the queue's `retry_after` (the `REDIS_QUEUE_RETRY_AFTER` line above, or `DB_QUEUE_RETRY_AFTER` on the database driver) set above that timeout, or a still-running import is handed to a second worker as a duplicate. The hourly `openyacht:sync-media` sweep re-queues any import that died anyway.

**Before every deploy** — the same three gates, every time; deploys pull from the repository, so anything unpushed or unchecked simply is not what ships:

1. `composer preflight` — the fixers (Pint, ESLint, Prettier) followed by the exact checks CI runs (format, frontend types, PHPStan, the full test suite). Running individual tools on the files you touched is not enough: format and static-analysis drift accumulates precisely on the files you _didn't_ touch, and CI checks everything.
2. Push, and wait for **both** CI jobs — `ci` and `tests-mysql` — to go green. The MySQL job is the cross-database gate; SQLite passing locally proves nothing about engine divergence.
3. Deploy the commit CI approved, not a newer local one.

Then, from a checkout: `DEPLOY_HOST=your.domain vendor/bin/dep deploy test`. Deployer needs PHP 8.4+ locally (the lockfile's floor) and shells out to `ssh`, so run it from a Unix shell — Windows OpenSSH implements no `ControlMaster`, and Deployer's default connection multiplexing fails there on every task with `getsockname failed: Not a socket`; from Windows, pass `-o ssh_multiplexing=false`. Point the connection at its key with an `~/.ssh/config` entry rather than editing `deploy.php`, which deliberately carries no one's local paths.

The first run stops at the missing shared `.env`. Create it at `{{deploy_path}}/shared/.env` (`APP_KEY` via `php artisan key:generate --show`, database credentials, the `OPENYACHT_*` identity variables, mail credentials, **and the Redis driver block above** — the provisioning script installed Redis for exactly this; leave it out and the node silently runs cache, sessions, and queue on MySQL forever), deploy again, then inside `current/` run once:

```bash
php artisan openyacht:install                      # roles, node UUID, first federation keypair
php artisan optimize:clear && php artisan optimize  # REQUIRED, see below
php artisan openyacht:create-user                  # interactive; needs a TTY
```

The cache rebuild is not optional, and its position is the point: the deploy's own `artisan:optimize` caches config _before_ `openyacht:install` writes `OPENYACHT_NODE_UUID` into `.env`, so skipping it leaves the node serving a discovery document with `"uuid": null` — a partner reading that sees a node with no identity, and nothing else appears wrong. `openyacht:create-user` prompts through Laravel Prompts and cannot be piped or scripted; run it on an interactive shell. There is no web UI for creating users — self-registration is disabled by design, so every account is minted with this command.

Every later deploy is the single `dep deploy` command: it builds assets on the server, migrates, restarts the queue worker, and swaps the `current` symlink atomically.

## Tests are the conformance story

The Pest suite is grouped by the spec's conformance IDs — the test run _is_ the self-certification:

```bash
php artisan test                                   # SQLite
vendor/bin/pest --configuration=phpunit.mysql.xml  # identical suite on MySQL
php artisan test --group=FP-7                      # a single conformance ID
```

Cross-database parity is enforced: both lanes run in CI, and engine-specific traps are documented in `.ai/rules/`.

## Vendored registries

`resources/registry/` holds vendored copies of the shared-vocabulary registries (`builders.json`, `categories.json`, `destinations.json`) from the protocol repository. They are validation lists, updated out-of-band — never fetched at request time.

## Vendored schemas and the drift check

`resources/schemas/v1/` holds byte-identical copies of the protocol repository's published JSON Schemas. `tests/Feature/Federation/SchemaConformanceTest.php` validates every document the node emits — well-known, capabilities, errors, listings, tombstones, feed pages — against them, so the suite fails when output stops being well-formed, not only when behaviour changes.

While the protocol is a draft it is amended without a version bump, so `protocol_versions` cannot tell a node it has fallen behind. The scheduled `schema-drift` workflow covers that: weekly it compares the vendored schemas with the published ones, and when they differ it runs the same conformance tests against the published copies and opens an issue naming what would fail. It never blocks a merge. To adopt an amendment, copy the published `schemas/v1` over `resources/schemas/v1` and fix what the suite then reports; a change to what a listing serves on the wire also needs a migration stamping `federation_updated_at` for the affected listings.

## Contributing

Bug reports, conformance gaps, and patches are welcome — see [CONTRIBUTING.md](CONTRIBUTING.md). The protocol itself is amended in the [protocol repository](https://github.com/OpenYacht/protocol), not here. Vulnerabilities go through [SECURITY.md](SECURITY.md), privately.

## License

Copyright (C) 2026 The OpenYacht contributors.

[AGPL-3.0-only](LICENSE). Run it, study it, lift the patterns — and if you operate a modified version as a network service, share your changes the same way.

The protocol is nobody's property: the spec, schemas, and registries carry their own permissive licenses (CC-BY-4.0 and MIT) so any implementation, open or proprietary, can embed them verbatim. The copyleft here covers this application's source, not the protocol it speaks.
