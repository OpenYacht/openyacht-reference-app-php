<?php

namespace App\Console\Commands;

use App\Services\Federation\NodeDirectory;
use Illuminate\Console\Command;
use InvalidArgumentException;
use RuntimeException;

/**
 * Generate the signed two-line node-directory listing request. The
 * directory is an advisory phonebook — being in it grants nothing and
 * being absent costs nothing; the signature only proves the operator
 * controls the domain being listed, delisted, or amended.
 *
 * // federation-protocol.md §Finding partners: the node directory — The listing token
 */
class OpenYachtListingToken extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'openyacht:listing-token {action=list : list, delist, or amend}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Generate a signed node-directory listing request';

    public function handle(NodeDirectory $directory): int
    {
        try {
            $request = $directory->listingRequest((string) $this->argument('action'));
        } catch (InvalidArgumentException|RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->line("Token:     {$request['token']}");
        $this->line("Signature: {$request['signature']}");
        $this->newLine();
        $this->info('Valid for ±30 days. Paste both lines into the node-listing issue form: https://github.com/OpenYacht/protocol/issues/new?template=node-listing.yml');

        return self::SUCCESS;
    }
}
