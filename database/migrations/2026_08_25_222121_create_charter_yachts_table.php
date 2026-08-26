<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * This node's own charter listings — the records it is the authority for.
 *
 * Sale and charter listings never share a table: the listing type is part
 * of a listing's identity, immutable like the canonical UUID (ID-1), so
 * here the table IS the type. A vessel both for sale and for charter is
 * two listings — two rows in two tables — linked by their shared vessel.
 * Charter listings carry no asking price (the wire's listing.price is
 * null by design); their pricing is the rate block, stored alongside the
 * other charter-only facts (operating areas, base ports, crew) in JSON
 * columns the ListingSerializer emits shape-complete (LS-1).
 *
 * crew_attested_at records the charter-manager/captain attestation that
 * authorises distributing crew data (LS-15); crew is withheld from the
 * wire while it is null.
 *
 * // yacht-identity.md §Listing Identity, listing-schema.md §Charter
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('charter_yachts', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('vessel_id')->constrained()->restrictOnDelete();
            $table->foreignId('assigned_broker_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 16)->index();
            $table->string('name');
            $table->text('summary')->nullable();
            $table->string('condition', 8)->nullable();
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
            $table->json('rates')->nullable();
            $table->json('operating_areas')->nullable();
            $table->string('summer_base_port')->nullable();
            $table->string('winter_base_port')->nullable();
            $table->json('crew')->nullable();
            $table->timestamp('crew_attested_at')->nullable();
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
        Schema::dropIfExists('charter_yachts');
    }
};
