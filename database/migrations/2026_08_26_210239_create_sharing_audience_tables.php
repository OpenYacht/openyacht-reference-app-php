<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-listing, per-partner sharing (wordpress-plugin-notes.md §Granular
 * sharing). Listings gain an audience (everyone|selected|none); a
 * selected audience is the union of individually selected partners and
 * members of selected partner groups. Every per-partner visibility
 * change lands in the append-only visibility_events log, which the feed
 * replays against any updated_since watermark.
 *
 * The listing side of every association is the canonical UUID, not a row
 * id: sale and charter listings live in separate tables whose row ids
 * collide, while UUIDs are unique across both and immutable (ID-1).
 *
 * No federation_updated_at stamp is needed: every listing starts with
 * audience everyone, so nothing's wire form or visibility changes here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sale_yachts', function (Blueprint $table): void {
            $table->string('audience', 16)->default('everyone');
        });

        Schema::table('charter_yachts', function (Blueprint $table): void {
            $table->string('audience', 16)->default('everyone');
        });

        Schema::create('partner_groups', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->unique();
            $table->timestamps();
        });

        Schema::create('partner_group_members', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('partner_group_id')->constrained()->cascadeOnDelete();
            $table->foreignId('federation_partner_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['partner_group_id', 'federation_partner_id']);
            $table->index('federation_partner_id');
        });

        Schema::create('listing_audience_partners', function (Blueprint $table): void {
            $table->id();
            $table->uuid('listing_uuid');
            $table->foreignId('federation_partner_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['listing_uuid', 'federation_partner_id']);
            $table->index('federation_partner_id');
        });

        Schema::create('listing_audience_groups', function (Blueprint $table): void {
            $table->id();
            $table->uuid('listing_uuid');
            $table->foreignId('partner_group_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['listing_uuid', 'partner_group_id']);
            $table->index('partner_group_id');
        });

        // Append-only; no foreign keys by design — the log outlives any
        // row it references and must never block a delete.
        Schema::create('visibility_events', function (Blueprint $table): void {
            $table->id();
            $table->uuid('listing_uuid');
            $table->unsignedBigInteger('federation_partner_id');
            $table->string('event', 16);
            $table->dateTime('occurred_at');
            // The feed's latest-event-per-listing lookup for one partner.
            $table->index(['federation_partner_id', 'listing_uuid', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('visibility_events');
        Schema::dropIfExists('listing_audience_groups');
        Schema::dropIfExists('listing_audience_partners');
        Schema::dropIfExists('partner_group_members');
        Schema::dropIfExists('partner_groups');
        Schema::table('sale_yachts', fn (Blueprint $table) => $table->dropColumn('audience'));
        Schema::table('charter_yachts', fn (Blueprint $table) => $table->dropColumn('audience'));
    }
};
