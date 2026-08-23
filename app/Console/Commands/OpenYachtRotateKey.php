<?php

namespace App\Console\Commands;

use App\Services\Federation\KeyManager;
use Illuminate\Console\Command;

/**
 * Key rotation. Routine rotation publishes the new key alongside the old
 * (retiring) one so partners' caches recover without coordination; after
 * the overlap window (RECOMMENDED: 48 hours) run --retire to revoke
 * retiring keys. Emergency rotation revokes everything immediately —
 * partners' next verification fails, triggers a well-known refetch, and
 * recovers automatically.
 *
 * // federation-protocol.md §Key Rotation
 */
class OpenYachtRotateKey extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'openyacht:key:rotate
        {--emergency : Revoke every existing key immediately, without an overlap window}
        {--retire : Revoke retiring keys after the overlap window, instead of rotating}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Rotate the federation signing key (routine, emergency, or post-overlap retirement)';

    public function handle(KeyManager $keys): int
    {
        if ($this->option('retire')) {
            $retired = $keys->retireOverlappedKeys();
            $this->info("{$retired} retiring key(s) revoked and removed from the well-known document.");

            return self::SUCCESS;
        }

        if ($this->option('emergency')) {
            $key = $keys->rotateEmergency();
            $this->warn('Emergency rotation: every previous key is revoked immediately. Document the incident.');
            $this->info("New active key: {$key->key_id}");

            return self::SUCCESS;
        }

        $key = $keys->rotate();
        $this->info("New active key: {$key->key_id}. The previous key stays published as retiring.");
        $this->line('After the overlap window (recommended: 48 hours), run: php artisan openyacht:key:rotate --retire');

        return self::SUCCESS;
    }
}
