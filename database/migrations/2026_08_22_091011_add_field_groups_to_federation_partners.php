<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Field-group grants per partner (LS-14): pricing, location_exact,
 * media_original, documents, vessel_identifiers, history. Responses are
 * filtered server-side to these grants (API-5); withheld values are
 * nulled/emptied, never sent with "please ignore" semantics.
 *
 * // yacht-identity.md §Sharing Permissions and Usage Terms
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('federation_partners', function (Blueprint $table) {
            $table->json('field_groups')->nullable()->after('trust_level');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('federation_partners', function (Blueprint $table) {
            $table->dropColumn('field_groups');
        });
    }
};
