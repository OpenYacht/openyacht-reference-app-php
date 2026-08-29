<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The per-partner import type preference: whether this node projects the
 * partner's sale listings, charter listings, or both for display. A
 * sales-only brokerage never sees a partner's charters, not even in the
 * review queue. Copies are always stored regardless — sync is the
 * substrate, this only gates projection (the same split the acceptance
 * policy draws).
 *
 * Consumer-side only: nothing here changes any listing's wire form, so
 * no federation_updated_at stamp is needed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('federation_partners', function (Blueprint $table): void {
            $table->string('import_types', 16)->default('both');
        });
    }

    public function down(): void
    {
        Schema::table('federation_partners', fn (Blueprint $table) => $table->dropColumn('import_types'));
    }
};
