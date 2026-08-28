<?php

namespace App\Services\Federation;

use RuntimeException;

/**
 * Raised when an outbound federation request is refused because its
 * target host is not a public destination we are willing to connect to
 * (an IP literal, a host with a port/path, or a name that resolves into
 * a private/reserved range). Guards against SSRF from attacker- or
 * partner-supplied hostnames.
 */
class BlockedOutboundHost extends RuntimeException {}
