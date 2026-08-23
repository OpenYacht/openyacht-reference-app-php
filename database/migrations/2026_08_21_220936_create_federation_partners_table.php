<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Federation partners, per the reference partner record.
 *
 * Extensions over the reference schema, for sync bookkeeping:
 * last_synced_at (the updated_since watermark, taken from the authority's
 * meta.generated_at to avoid clock skew) and last_attempted_at (drives
 * exponential backoff between failed sync attempts).
 *
 * The trust_level column is a plain string (constrained by the TrustLevel
 * enum cast) rather than a native ENUM, for SQLite/MySQL/MariaDB
 * compatibility.
 *
 * // federation-protocol.md §Partner Lifecycle — Partner record (reference)
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('federation_partners', function (Blueprint $table) {
            $table->id();
            $table->string('domain')->unique();
            $table->string('node_uuid', 36)->nullable();
            $table->json('keys_json')->nullable();
            $table->timestamp('keys_fetched_at')->nullable();
            $table->string('pinned_key_id', 16)->nullable();
            $table->string('trust_level', 16)->index();
            $table->foreignId('approved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('last_ok_at')->nullable();
            $table->unsignedInteger('consecutive_failures')->default(0);
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamp('last_attempted_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('federation_partners');
    }
};
