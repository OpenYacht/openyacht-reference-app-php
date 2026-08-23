<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Federation signing keys, per the reference storage schema.
 *
 * The status column is a plain string (constrained by the KeyStatus enum
 * cast) rather than a native ENUM, for SQLite/MySQL/MariaDB compatibility.
 *
 * // federation-protocol.md §Keys — Storage schema (reference)
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('federation_keys', function (Blueprint $table) {
            $table->id();
            $table->string('key_id', 16)->unique();
            $table->text('private_key');
            $table->text('public_key');
            $table->string('status', 16)->index();
            $table->timestamp('retired_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('federation_keys');
    }
};
