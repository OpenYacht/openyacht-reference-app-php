<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-endpoint scheduled pings: the "freshness floor" for consumers that
 * rebuild from data the node cannot see change (exchange rates, partners'
 * well-known documents). A consumer is guaranteed a ping at least every
 * schedule_interval_minutes, measured from whatever ping it last received
 * — so a busy node with plenty of change notifications never gets an
 * extra scheduled build on top. Null means no schedule. Configured in the
 * admin, so it lives here and not in any consumer's CI.
 *
 * Consumer-side only: nothing here changes any listing's wire form, so
 * no federation_updated_at stamp is needed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('webhook_endpoints', function (Blueprint $table): void {
            $table->unsignedInteger('schedule_interval_minutes')->nullable()->after('is_active');
            $table->timestamp('last_notified_at')->nullable()->after('schedule_interval_minutes');
        });
    }

    public function down(): void
    {
        Schema::table('webhook_endpoints', fn (Blueprint $table) => $table->dropColumn(['schedule_interval_minutes', 'last_notified_at']));
    }
};
