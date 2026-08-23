<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Curated imports: listing copies a human chose to display, materialised
 * into queryable columns. An imported yacht is a projection of its copy —
 * the copy (and its provenance) remains the source of truth, updates
 * propagate from sync within 24 hours (ID-7), and the projection is
 * removed when the listing ends and its usage terms expire (ID-10).
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('imported_yachts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('listing_copy_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('imported_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('name');
            $table->string('type', 16);
            $table->string('status', 16)->index();
            $table->string('builder_name')->nullable();
            $table->string('model_name')->nullable();
            $table->unsignedSmallInteger('year_built')->nullable();
            $table->decimal('loa_m', 6, 2)->nullable();
            $table->string('price_amount', 32)->nullable();
            $table->string('price_currency', 3)->nullable();
            $table->string('location_display')->nullable();
            $table->text('summary')->nullable();
            $table->string('attribution_text')->nullable();
            $table->timestamp('media_synced_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('imported_yachts');
    }
};
