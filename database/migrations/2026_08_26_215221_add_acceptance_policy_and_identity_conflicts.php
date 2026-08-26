<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The per-partner acceptance policy (sync is not publication — accepting
 * a synced copy for display is a partner-level policy, not a per-listing
 * question) and the vessel-identity conflict flag it must consult:
 * hard-matched vessels are retained, flagged for human review, and never
 * auto-resolved (ID-9); soft candidate matches queue for human
 * confirmation (yacht-identity.md §Vessel Identity).
 *
 * Consumer-side only: nothing here changes any listing's wire form, so
 * no federation_updated_at stamp is needed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('federation_partners', function (Blueprint $table): void {
            $table->string('acceptance_policy', 32)->default('review');
        });

        Schema::table('listing_copies', function (Blueprint $table): void {
            // Null: no conflict. Non-null: a list of matches, each
            // {with, matched_on, label, uuid} — consulted by every
            // auto-publish path before importing.
            $table->json('identity_conflicts')->nullable();
            $table->dateTime('conflict_reviewed_at')->nullable();
        });

        Schema::table('imported_yachts', function (Blueprint $table): void {
            // Set when the acceptance policy published the listing with no
            // human in the loop — the "recently auto-published" review
            // feed keys on it (review after, not review before).
            $table->dateTime('auto_published_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('federation_partners', fn (Blueprint $table) => $table->dropColumn('acceptance_policy'));
        Schema::table('listing_copies', fn (Blueprint $table) => $table->dropColumn(['identity_conflicts', 'conflict_reviewed_at']));
        Schema::table('imported_yachts', fn (Blueprint $table) => $table->dropColumn('auto_published_at'));
    }
};
