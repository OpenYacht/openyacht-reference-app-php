---
paths:
    - 'app/Http/Controllers/App/**'
---

# Controllers App

## Every app page authorizes; partner listing views need a listings permission

Every App controller action starts with Gate::authorize — no page is "any authenticated user". Self-registration is disabled by design (users come from `openyacht:create-user`), so an account alone grants nothing: a user with no role sees only the dashboard. Synced/imported partner listing pages authorize viewAny/view on ListingCopy / ImportedYacht (ManageListings or ManageOwnListings) — partner copies are shared business data, never public. When adding a nav item in AppSidebar.vue, gate it behind the matching `auth.can*` shared prop from HandleInertiaRequests.
