---
paths:
    - 'tests/Feature/**'
---

# Feature

## Queue::fake keeps unique-job locks — release them to assert a second dispatch

DeliverSubscriptionChange is ShouldBeUniqueUntilProcessing; the lock is acquired in PendingDispatch even under Queue::fake and, since nothing processes, never released — a second dispatch for the same (partner, listing) in the same test is silently skipped (assertPushed count stays 1). That IS the coalescing behaviour, so assert it, and release with (new UniqueLock(Cache::store()))->release($job) to simulate the worker taking the job before asserting a fresh dispatch. Without Queue::fake the sync driver processes inline and releases the lock, so Http::fake-based delivery tests are unaffected.
