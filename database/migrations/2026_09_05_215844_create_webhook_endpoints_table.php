<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Outbound change-notification webhooks, managed in the admin instead of
 * the retired OPENYACHT_CHANGE_NOTIFY_URLS / _SECRET env keys: one row
 * per consumer with its own secret, on/off switch and delivery record,
 * so several sites can be fed from one node. Deliveries are a bounded
 * per-endpoint log (the model trims it) — enough to answer "did my
 * rebuild fire", not an archive.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('webhook_endpoints', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('url', 2000);
            $table->text('secret')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_succeeded_at')->nullable();
            $table->timestamp('last_failed_at')->nullable();
            $table->unsignedInteger('consecutive_failures')->default(0);
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('webhook_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('webhook_endpoint_id')->constrained('webhook_endpoints')->cascadeOnDelete();
            $table->string('reason', 1000);
            $table->unsignedTinyInteger('attempt');
            $table->boolean('succeeded');
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->string('error', 1000)->nullable();
            $table->unsignedInteger('duration_ms');
            $table->timestamp('created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('webhook_deliveries');
        Schema::dropIfExists('webhook_endpoints');
    }
};
