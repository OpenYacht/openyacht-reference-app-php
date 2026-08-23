<?php

namespace App\Enums;

/**
 * Lifecycle of a federation signing key.
 *
 * Active and retiring keys are both published in the well-known document so
 * routine rotation can overlap; revoked keys are withdrawn from publication.
 *
 * // federation-protocol.md §Keys, §Key Rotation
 */
enum KeyStatus: string
{
    /** The key currently used to sign outbound requests. */
    case Active = 'active';

    /** Still published for verification during a rotation overlap window. */
    case Retiring = 'retiring';

    /** Withdrawn; never published or accepted again. */
    case Revoked = 'revoked';
}
