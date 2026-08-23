<?php

namespace App\Console\Commands;

use App\Services\Federation\KeyManager;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * One-time node installation: generates the node UUID and the first
 * Ed25519 federation keypair. Idempotent — safe to re-run.
 *
 * // federation-protocol.md §Identity and Trust Model, §Keys
 */
class OpenYachtInstall extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'openyacht:install';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Generate the node UUID and initial federation keypair';

    public function handle(KeyManager $keys): int
    {
        if (! config('openyacht.domain')) {
            $this->warn('OPENYACHT_DOMAIN is not set. Set it in .env before going live — the identity domain is permanent in practice.');
        }

        if (config('openyacht.node_uuid')) {
            $this->info('Node UUID already set: '.config('openyacht.node_uuid'));
        } else {
            $uuid = (string) Str::uuid7();
            $this->writeEnvValue('OPENYACHT_NODE_UUID', $uuid);
            config(['openyacht.node_uuid' => $uuid]);
            $this->info("Node UUID generated: {$uuid}");
        }

        if ($activeKey = $keys->activeKey()) {
            $this->info("Active federation key already exists: {$activeKey->key_id}");
        } else {
            $key = $keys->generate();
            $this->info("Federation keypair generated: {$key->key_id}");
        }

        return self::SUCCESS;
    }

    /**
     * Set or append a value in the application's .env file.
     */
    private function writeEnvValue(string $name, string $value): void
    {
        $path = base_path('.env');

        if (! file_exists($path)) {
            $this->warn("No .env file found; set {$name}={$value} manually.");

            return;
        }

        $contents = file_get_contents($path);

        if ($contents === false) {
            $this->warn("Could not read .env; set {$name}={$value} manually.");

            return;
        }

        if (preg_match("/^{$name}=.*/m", $contents)) {
            $contents = preg_replace("/^{$name}=.*/m", "{$name}={$value}", $contents);
        } else {
            $contents = rtrim($contents, "\n")."\n\n{$name}={$value}\n";
        }

        file_put_contents($path, $contents);
    }
}
