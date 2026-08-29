<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Daily ECB reference rates, EUR-based (EUR itself is implicitly 1.0 and
 * has no row). Refreshed by the scheduled SyncExchangeRates job; consumed
 * only by this node's cross-currency price search — never by anything
 * that touches a stored price or the wire (the wire says what a price
 * is; comparability is a local concern).
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('exchange_rates', function (Blueprint $table) {
            $table->id();
            $table->string('currency', 3)->unique();
            $table->decimal('rate', 12, 6);
            $table->date('published_at');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('exchange_rates');
    }
};
