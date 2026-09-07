<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Both ends of the partnership request the lifecycle opens with
 * (federation-protocol.md §Partner Lifecycle step 1).
 *
 * Outbound: request_sent_at is when this node's signed request last
 * reached the partner (delivered or already accepted) — the operator's
 * evidence that the other side has something to approve.
 *
 * Inbound: a partner's request carries a human-readable message and a
 * contact address for the person who decides whether to approve it.
 * Until now they were written only to the activity log, which nothing
 * read; stored on the row they can be shown beside the approve/block
 * buttons and in the first-contact mail.
 *
 * Consumer-side only: nothing here changes any listing's wire form, so
 * no federation_updated_at stamp is needed. Plain nullable columns —
 * no defaults, no changes to existing columns — so SQLite, MySQL and
 * MariaDB all take it as-is.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('federation_partners', function (Blueprint $table): void {
            $table->timestamp('request_sent_at')->nullable();
            $table->text('request_message')->nullable();
            $table->string('request_contact_email')->nullable();
            $table->timestamp('requested_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('federation_partners', function (Blueprint $table): void {
            $table->dropColumn(['request_sent_at', 'request_message', 'request_contact_email', 'requested_at']);
        });
    }
};
