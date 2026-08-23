<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The partner's display name from its well-known document
     * (node.name), captured on add and refreshed with the keys. Display
     * only — identity remains the domain.
     */
    public function up(): void
    {
        Schema::table('federation_partners', function (Blueprint $table): void {
            $table->string('node_name')->nullable()->after('domain');
        });
    }

    public function down(): void
    {
        Schema::table('federation_partners', function (Blueprint $table): void {
            $table->dropColumn('node_name');
        });
    }
};
