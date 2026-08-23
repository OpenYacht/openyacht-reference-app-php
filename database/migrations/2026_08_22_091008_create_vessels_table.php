<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The physical boat, separate from any listing of it. Real-world
 * identifiers exist for matching, not authority (yacht-identity.md
 * §Vessel Identity); all nullable — nulls that say "unknown" beat guesses.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('vessels', function (Blueprint $table) {
            $table->id();
            $table->string('hin', 64)->nullable();
            $table->string('imo', 16)->nullable();
            $table->string('mmsi', 16)->nullable();
            $table->string('official_number', 32)->nullable();
            $table->string('builder_name')->nullable();
            $table->string('builder_slug')->nullable();
            $table->string('model_name')->nullable();
            $table->string('model_slug')->nullable();
            $table->unsignedSmallInteger('year_built')->nullable();
            $table->unsignedSmallInteger('refit_year')->nullable();
            $table->decimal('loa_m', 6, 2)->nullable();
            $table->json('previous_names')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('vessels');
    }
};
