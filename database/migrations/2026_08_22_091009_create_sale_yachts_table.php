<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * This node's own sale listings — the records it is the authority for.
 *
 * The canonical UUID is minted once at creation and never changes (ID-1).
 * Columns cover the commercial facts the app queries on; the long tail of
 * the wire schema (specifications, descriptions, features, compliance)
 * lives in JSON columns, and the ListingSerializer emits the complete
 * wire shape with nulls for anything absent (LS-1).
 *
 * // yacht-identity.md §Listing Identity, listing-schema.md
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('sale_yachts', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('vessel_id')->constrained()->restrictOnDelete();
            $table->foreignId('assigned_broker_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 16)->index();
            $table->string('name');
            $table->text('summary')->nullable();
            $table->string('condition', 8)->nullable();
            $table->string('price_amount', 32)->nullable();
            $table->string('price_currency', 3)->nullable();
            $table->boolean('price_on_application')->default(false);
            $table->boolean('starting_price')->default(false);
            $table->string('location_display')->nullable();
            $table->string('location_city')->nullable();
            $table->string('location_state')->nullable();
            $table->string('location_country', 2)->nullable();
            $table->string('location_marina')->nullable();
            $table->decimal('location_lat', 10, 7)->nullable();
            $table->decimal('location_lon', 10, 7)->nullable();
            $table->json('specifications')->nullable();
            $table->json('descriptions')->nullable();
            $table->json('features')->nullable();
            $table->json('compliance')->nullable();
            $table->timestamp('listed_at')->nullable();
            // Drives updated_since sync (API-2): touched on every
            // federation-visible change, indexed for the hot path.
            $table->timestamp('federation_updated_at')->nullable()->index();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sale_yachts');
    }
};
