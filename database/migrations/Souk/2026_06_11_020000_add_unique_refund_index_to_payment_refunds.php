<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Make refund-by-processor-id dedup race-safe.
 *
 * Webhooks are delivered at-least-once and retried, so the same refund event can
 * arrive twice concurrently. A check-then-insert (`exists()` then `save()`) can let
 * both pass the check and write duplicate payment_refunds rows, over-counting toward
 * isFullyRefunded(). A unique index makes the dedup atomic — the second insert fails
 * and the job treats it as "already recorded".
 *
 * Nullable processor_refund_id is allowed to repeat (MySQL permits multiple NULLs in a
 * unique index), so PENDING rows created before the processor id is known are unaffected.
 */
return new class () extends Migration {
    protected $connection = 'commerce';

    public function up(): void
    {
        Schema::connection('commerce')->table('payment_refunds', function (Blueprint $table) {
            $table->unique(['payments_id', 'processor_refund_id'], 'payment_refunds_payment_processor_unique');
        });
    }

    public function down(): void
    {
        Schema::connection('commerce')->table('payment_refunds', function (Blueprint $table) {
            $table->dropUnique('payment_refunds_payment_processor_unique');
        });
    }
};
