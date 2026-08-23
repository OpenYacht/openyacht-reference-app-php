<?php

namespace App\Services\Federation;

use InvalidArgumentException;
use RuntimeException;

/**
 * The node-directory consent surface: signed listing requests for the
 * project's advisory node phonebook. The same Ed25519 key that signs
 * federation requests proves listing consent, so nobody can list — or
 * delist — a domain they do not control. The directory grants nothing;
 * this only produces the two lines the operator pastes into the
 * project's listing issue form.
 *
 * // federation-protocol.md §Finding partners: the node directory — The listing token
 */
class NodeDirectory
{
    public const ACTIONS = ['list', 'delist', 'amend'];

    public function __construct(private KeyManager $keys) {}

    /**
     * @return array{token: string, signature: string}
     */
    public function listingRequest(string $action): array
    {
        if (! in_array($action, self::ACTIONS, true)) {
            throw new InvalidArgumentException("Unknown node-directory action [{$action}].");
        }

        $domain = strtolower((string) config('openyacht.domain'));

        if ($domain === '') {
            throw new RuntimeException('OPENYACHT_DOMAIN is not set — the token names the identity domain being listed.');
        }

        $key = $this->keys->activeKey();

        if ($key === null) {
            throw new RuntimeException('No active federation key — run openyacht:install first.');
        }

        $token = sprintf(
            'openyacht-node-listing:v1:%s:%s:%s',
            $domain,
            $action,
            now('UTC')->format('Y-m-d'),
        );

        return [
            'token' => $token,
            'signature' => base64_encode(sodium_crypto_sign_detached($token, $key->rawSecretKey())),
        ];
    }
}
