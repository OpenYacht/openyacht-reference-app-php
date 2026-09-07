---
paths:
    - app/Models/Concerns/FederatedListing.php
---

# Concerns

## Push deliveries hang off saved + isDirty, the one sanctioned exception to "never saved"

The push-subscription dispatch in FederatedListing is the deliberate exception to the "hook created/updated, never saved" rule: it must run AFTER every other created/updated listener (SaleYacht::booted appends the price-history row in one), or a delivery that runs at once on a synchronous queue serialises an incomplete listing — this bit on 2026-09-07. The phantom-save trap the rule guards against is avoided by guarding with isDirty('federation_updated_at'), never wasChanged(): during saved the original is not yet synced, so isDirty is true exactly when THIS save wrote the stamp and false for a no-op save. Keep ChangeNotifier on created/updated as before.
