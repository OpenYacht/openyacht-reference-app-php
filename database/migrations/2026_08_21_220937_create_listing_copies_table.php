<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Copies of partners' listings. Copies live in their own table — they are
 * not local listings and are never served from this node's /listings
 * output (ID-4). Every copy carries the mandatory provenance block (ID-3);
 * the canonical URI is stored and compared as an opaque string (ID-2).
 *
 * // yacht-identity.md §What everyone else holds
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('listing_copies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('federation_partner_id')->constrained()->cascadeOnDelete();
            $table->string('canonical_uri', 500)->unique();
            $table->string('authority_domain');
            $table->string('type', 16);
            $table->string('status', 16)->index();
            $table->string('name')->nullable();
            $table->json('payload')->nullable();
            $table->timestamp('listing_updated_at')->nullable();
            $table->timestamp('received_at');
            $table->boolean('signature_verified')->default(false);
            $table->timestamp('tombstoned_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('listing_copies');
    }
};
