<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Locally generated renditions of an imported yacht's media. One row per
 * source image; the renditions column maps each generated variant
 * (sm/md/lg, and crop_* for the profile hero) to its stored path and
 * dimensions. Cached media inherits the listing's usage terms including
 * expires_with_listing (yacht-identity.md §Media Identity).
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('imported_media', function (Blueprint $table) {
            $table->id();
            $table->foreignId('imported_yacht_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 16)->index();
            $table->string('source_url', 1000);
            $table->string('source_sha256', 64)->nullable();
            $table->string('caption')->nullable();
            $table->unsignedInteger('sort')->default(0);
            $table->json('renditions');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('imported_media');
    }
};
