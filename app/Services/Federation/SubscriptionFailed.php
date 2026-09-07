<?php

namespace App\Services\Federation;

use RuntimeException;

/**
 * Raised when this node could not subscribe to, or unsubscribe from, a
 * partner's pushes. The message is already translated for the operator.
 */
class SubscriptionFailed extends RuntimeException {}
