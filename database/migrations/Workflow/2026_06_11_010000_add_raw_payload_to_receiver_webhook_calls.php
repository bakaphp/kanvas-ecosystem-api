<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Store the raw, unparsed request body for each receiver webhook call.
 *
 * `payload` is the decoded `$request->input()` array (cast through Baka\Casts\Json).
 * Re-serialising that array changes bytes (key order, whitespace, unicode escaping),
 * so any signature scheme that hashes the exact body — Stripe's
 * `Webhook::constructEvent`, GitHub's HMAC, etc. — fails when verified from `payload`.
 * `raw_payload` keeps the original bytes so deferred (queued) webhook jobs can verify
 * the signature at processing time, not only synchronously at receive time.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::table('receiver_webhook_calls', function (Blueprint $table) {
            if (! Schema::hasColumn('receiver_webhook_calls', 'raw_payload')) {
                $table->longText('raw_payload')->nullable()->after('payload');
            }
        });
    }

    public function down(): void
    {
        Schema::table('receiver_webhook_calls', function (Blueprint $table) {
            if (Schema::hasColumn('receiver_webhook_calls', 'raw_payload')) {
                $table->dropColumn('raw_payload');
            }
        });
    }
};
