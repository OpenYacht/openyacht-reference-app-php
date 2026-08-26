<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Videos and virtual tours are external-platform links (YouTube, Vimeo,
 * Matterport…), not files this node hosts — stored as JSON lists of
 * {url, caption}, serialised per listing-schema.md §Media with sha256
 * null for external URLs. Layouts and documents, which ARE hosted
 * files, live in the media library (collections added alongside).
 *
 * No federation_updated_at stamp: both columns start null and empty
 * lists were already what the wire served, so no listing's wire form
 * changes here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sale_yachts', function (Blueprint $table): void {
            $table->json('videos')->nullable();
            $table->json('tours')->nullable();
        });

        Schema::table('charter_yachts', function (Blueprint $table): void {
            $table->json('videos')->nullable();
            $table->json('tours')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('sale_yachts', fn (Blueprint $table) => $table->dropColumn(['videos', 'tours']));
        Schema::table('charter_yachts', fn (Blueprint $table) => $table->dropColumn(['videos', 'tours']));
    }
};
