<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The per-partner sharing scope: whether the partner receives the
 * everyone-audience catalogue (standard) or only listings explicitly
 * shared with it, directly or via a group (curated) — the yacht-show /
 * limited-partner case (wordpress-plugin-notes.md §Granular sharing).
 *
 * Per-partner visibility policy, not listing serialization: no
 * federation_updated_at stamp. The additive pivot rule that ships with
 * this column changes no partner's current view at upgrade time —
 * SharingService::setAudience() has always cleared pivot rows when an
 * audience leaves "selected", so no everyone-audience listing carries
 * pivot rows for the new rule to newly honour.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('federation_partners', function (Blueprint $table): void {
            $table->string('sharing_scope', 16)->default('standard');
        });
    }

    public function down(): void
    {
        Schema::table('federation_partners', fn (Blueprint $table) => $table->dropColumn('sharing_scope'));
    }
};
