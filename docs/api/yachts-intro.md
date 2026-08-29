# Yacht Data API

This guide describes the node's **data API** — the key-authenticated feed a
website, static-site build, or integration consumes yacht data from. It is
written for AI assistants and external integrations.

> The endpoint and parameter tables below are **generated from the
> application code**, so they always match the running API. The
> machine-readable OpenAPI spec at `/docs/api.json` is authoritative for
> exact request/response shapes. This API is distinct from the federation
> API (`/openyacht/v1/…`), which is node-to-node and request-signed.

## Base URL

```
https://{your-node-domain}/api/v1
```

## Payload: the protocol wire schema

Every yacht is served as a **complete listing document in the OpenYacht wire
schema** — the same shape the federation protocol distributes, documented
normatively in the protocol spec
([listing-schema.md](https://github.com/OpenYacht/protocol/blob/main/spec/listing-schema.md)).
This guide does not re-document those fields; it documents what is local to
this surface: authentication, query parameters, the response envelope, and
the `x_`-prefixed extension fields (the spec's own private-extension
mechanism) that carry source, provenance, attribution, and local media
renditions.

Served content is post-gate: listings whose usage terms forbid display, and
unsanitised rich text, never reach this API. Imported listings are copies
this node's operator chose to display; they are attributed to their
authority node and are **never redistributed over federation**.

## Authentication

Every request must include an API key, supplied any of these ways:

| Method | Example |
|--------|---------|
| HTTP header | `X-API-Key: your-key-here` |
| Bearer token | `Authorization: Bearer your-key-here` |
| Query parameter | `?api_key=your-key-here` |

### Scopes

- `yachts:read` — the yacht feed endpoints below.

### Domain restriction

Keys can be locked to specific domains. When configured, the `Origin` or
`Referer` header must match an allowed domain, otherwise the API responds
`403`. Use a domain-locked key for anything that runs in a browser (a
search island on a static site); keep unrestricted keys server-side.

### Rate limiting

Each key has its own per-minute rate limit. When exceeded the API returns
`429 Too Many Requests` with a `Retry-After` header (seconds). Clients
should honour it and back off.

## Pricing & currency

Prices are stored and served **exactly as the listing's authority published
them** — original amount, original currency, never rewritten. Comparability
across currencies is provided at query time: the node refreshes the
European Central Bank daily reference rates and converts your *search
bounds* into each currency present in inventory.

- `price_min` / `price_max` are expressed in `price_currency` (default
  `USD`) and match listings in every currency at the current rates.
- `sort=price` orders mixed-currency inventory by value converted into
  `price_currency`.
- There are **no silent fallbacks**: a `price_currency` without a fetched
  ECB rate is refused with `422`, and a listing priced in a currency the
  ECB does not publish simply never matches priced filters.
- Charter listings carry no asking price by design (their pricing is the
  charter rate block), so priced filters exclude them.

## Sweeping the feed

List responses contain **full documents** — a consumer pre-building pages
(e.g. a static-site build) never needs per-yacht detail calls. Request up
to `per_page=200` and follow `links.next` until it is `null`; at typical
inventory sizes a complete sweep is a handful of requests.
