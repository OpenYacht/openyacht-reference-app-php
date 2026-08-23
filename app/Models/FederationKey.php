<?php

namespace App\Models;

use App\Enums\KeyStatus;
use Database\Factories\FederationKeyFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * An Ed25519 federation signing keypair.
 *
 * The private key is the base64 of the raw 64-byte sodium secret key and is
 * encrypted at rest via the `encrypted` cast (FP-4). The public key is the
 * base64 of the raw 32 bytes, exactly as published in the well-known
 * document. The key ID is the first 16 hex characters of the SHA-256 of the
 * raw public key (FP-3).
 *
 * // federation-protocol.md §Keys
 *
 * @property int $id
 * @property string $key_id
 * @property string $private_key
 * @property string $public_key
 * @property KeyStatus $status
 * @property Carbon|null $retired_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['key_id', 'private_key', 'public_key', 'status', 'retired_at'])]
#[Hidden(['private_key'])]
class FederationKey extends Model
{
    /** @use HasFactory<FederationKeyFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'private_key' => 'encrypted',
            'status' => KeyStatus::class,
            'retired_at' => 'datetime',
        ];
    }

    /**
     * The raw 64-byte sodium secret key.
     *
     * @return non-empty-string
     */
    public function rawSecretKey(): string
    {
        $raw = base64_decode($this->private_key, strict: true);

        if ($raw === false || $raw === '') {
            throw new RuntimeException("Federation key [{$this->key_id}] holds an undecodable private key.");
        }

        return $raw;
    }

    /**
     * The raw 32-byte public key.
     *
     * @return non-empty-string
     */
    public function rawPublicKey(): string
    {
        $raw = base64_decode($this->public_key, strict: true);

        if ($raw === false || $raw === '') {
            throw new RuntimeException("Federation key [{$this->key_id}] holds an undecodable public key.");
        }

        return $raw;
    }
}
