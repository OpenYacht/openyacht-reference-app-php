<?php

namespace App\Services\Federation;

use RuntimeException;

/**
 * Raised by a push delivery when the partner's callback answered with a
 * non-2xx status. Thrown rather than swallowed so the queue retries the
 * delivery on its backoff schedule (API-10).
 */
class SubscriptionDeliveryFailed extends RuntimeException {}
