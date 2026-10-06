<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        if (! Schema::connection('commerce')->hasColumn('orders', 'paid_at')) {
            Schema::connection('commerce')->table('orders', function (Blueprint $table): void {
                $table->dateTime('paid_at')->nullable()->after('payment_status');
                $table->index(['apps_id', 'is_deleted', 'paid_at'], 'ix_orders_paid_at');
            });
        }
    }

    public function down(): void
    {
        if (Schema::connection('commerce')->hasColumn('orders', 'paid_at')) {
            Schema::connection('commerce')->table('orders', function (Blueprint $table): void {
                $table->dropIndex('ix_orders_paid_at');
                $table->dropColumn('paid_at');
            });
        }
    }
};
