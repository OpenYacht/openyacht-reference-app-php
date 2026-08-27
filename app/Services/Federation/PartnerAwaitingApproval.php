<?php

namespace App\Services\Federation;

use RuntimeException;

/**
 * The partner answered `PARTNER_PROVISIONAL` — "authenticated but not yet
 * approved for this resource" (api-design.md §Errors). The request was
 * delivered, signed correctly, and verified; a human on their side simply
 * has not approved the partnership yet.
 *
 * That makes it a normal state of a healthy partnership rather than a
 * failure, which matters because the spec's exponential backoff
 * (federation-protocol.md §Health and Failure Handling) is designed for
 * unreachable partners. Counting an awaiting-approval answer as a failure
 * punishes both sides for doing the handshake correctly: every polite poll
 * doubles the delay, so the moment approval finally arrives the node sits
 * idle for hours before it notices.
 */
class PartnerAwaitingApproval extends RuntimeException {}
