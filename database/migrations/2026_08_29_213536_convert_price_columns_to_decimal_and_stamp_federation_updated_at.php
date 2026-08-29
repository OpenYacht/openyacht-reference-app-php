<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Price columns become DECIMAL so cross-currency search can compare and
 * index them. Only local projection/authoring storage changes: the wire's
 * money_amount stays a string for byte-reproducibility, and the stored
 * copies in listing_copies.payload are untouched — imported_yachts is a
 * projection of those copies, and sale_yachts is storage this node is
 * the authority for.
 *
 * Serialized amounts gain two decimal places ("1500000" becomes
 * "1500000.00" — still valid money_amount), so every own sale listing
 * whose wire price or price_history changes is stamped, after the type
 * conversion, per the wire-changing-migration rule.
 *
 * // listing-schema.md §Price ($defs/money_amount)
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('sale_yachts', function (Blueprint $table) {
            $table->decimal('price_amount', 15, 2)->nullable()->change();
            $table->index(['price_currency', 'price_amount'], 'sale_yachts_price_index');
        });

        Schema::table('imported_yachts', function (Blueprint $table) {
            $table->decimal('price_amount', 15, 2)->nullable()->change();
            $table->index(['price_currency', 'price_amount'], 'imported_yachts_price_index');
        });

        Schema::table('price_histories', function (Blueprint $table) {
            $table->decimal('amount', 15, 2)->change();
        });

        DB::table('sale_yachts')
            ->where(function ($query) {
                $query->whereNotNull('price_amount')
                    ->orWhereIn('id', DB::table('price_histories')->select('sale_yacht_id'));
            })
            ->update(['federation_updated_at' => now()]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sale_yachts', function (Blueprint $table) {
            $table->dropIndex('sale_yachts_price_index');
            $table->string('price_amount', 32)->nullable()->change();
        });

        Schema::table('imported_yachts', function (Blueprint $table) {
            $table->dropIndex('imported_yachts_price_index');
            $table->string('price_amount', 32)->nullable()->change();
        });

        Schema::table('price_histories', function (Blueprint $table) {
            $table->string('amount', 32)->change();
        });
    }
};
