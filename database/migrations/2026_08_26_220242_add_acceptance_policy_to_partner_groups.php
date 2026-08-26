<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Groups carry an optional acceptance policy of their own — "trusted
 * partners auto-publish" is naturally a statement about a set, not a
 * per-partner chore. Null means the group contributes no policy; a
 * partner's effective policy is the most permissive of its own setting
 * and its groups' (FederationPartner::effectiveAcceptancePolicy).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('partner_groups', function (Blueprint $table): void {
            $table->string('acceptance_policy', 32)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('partner_groups', fn (Blueprint $table) => $table->dropColumn('acceptance_policy'));
    }
};
