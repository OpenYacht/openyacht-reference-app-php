<?php

namespace App\Services\Federation;

use RuntimeException;

/**
 * A partner's well-known document was unreachable, unparseable, or missing
 * required fields.
 */
class InvalidWellKnownDocument extends RuntimeException {}
