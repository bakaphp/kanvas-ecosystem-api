<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    protected $connection = 'commerce';

    public function up(): void
    {
        Schema::connection('commerce')->table('payment_refunds', function (Blueprint $table) {
            if (! Schema::connection('commerce')->hasColumn('payment_refunds', 'note')) {
                $table->text('note')->nullable()->after('reason');
            }

            if (! Schema::connection('commerce')->hasColumn('payment_refunds', 'idempotency_key')) {
                $table->string('idempotency_key', 100)->nullable()->after('processor_refund_id');
                $table->unique(['payments_id', 'idempotency_key'], 'payment_refunds_payment_idempotency_unique');
            }
        });

        Schema::connection('commerce')->table('payments', function (Blueprint $table) {
            if (! Schema::connection('commerce')->hasColumn('payments', 'captured_amount')) {
                $table->decimal('captured_amount', 10, 2)->nullable()->after('amount');
            }
        });
    }

    public function down(): void
    {
        Schema::connection('commerce')->table('payment_refunds', function (Blueprint $table) {
            $table->dropUnique('payment_refunds_payment_idempotency_unique');
            $table->dropColumn(['note', 'idempotency_key']);
        });

        Schema::connection('commerce')->table('payments', function (Blueprint $table) {
            $table->dropColumn('captured_amount');
        });
    }
};
