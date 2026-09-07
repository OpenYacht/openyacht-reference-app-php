<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Both halves of the optional push subscriptions (api-design.md
 * §Subscriptions), one row per partner because the spec allows exactly
 * one callback per partner.
 *
 * Authority side (API-10) — the partner subscribed to this node's
 * changes: push_callback_url is the HTTPS inbox it registered and this
 * node delivers to; push_callback_registered_at when; the two delivery
 * stamps are the operator's evidence that pushes are getting through.
 *
 * Consumer side (API-11) — this node subscribed to the partner's
 * changes: push_subscribed_at is when the partner accepted this node's
 * inbox as its callback. Its pushes are then accepted at
 * /openyacht/v1/inbox and its reconciliation poll drops to daily.
 *
 * Nothing here changes any listing's wire form, so no
 * federation_updated_at stamp is needed. Plain nullable columns — no
 * defaults, no changes to existing columns — so SQLite, MySQL and
 * MariaDB all take it as-is.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('federation_partners', function (Blueprint $table): void {
            $table->string('push_callback_url', 2000)->nullable();
            $table->timestamp('push_callback_registered_at')->nullable();
            $table->timestamp('push_last_delivered_at')->nullable();
            $table->timestamp('push_last_failed_at')->nullable();
            $table->timestamp('push_subscribed_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('federation_partners', function (Blueprint $table): void {
            $table->dropColumn([
                'push_callback_url',
                'push_callback_registered_at',
                'push_last_delivered_at',
                'push_last_failed_at',
                'push_subscribed_at',
            ]);
        });
    }
};
