<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::connection('commerce')->table('orders', function (Blueprint $table) {
            $table->decimal('commission_rate', 5, 2)->nullable()->after('tax_amount');
            $table->decimal('commission_amount', 10, 2)->nullable()->after('commission_rate');
            $table->decimal('provider_amount', 10, 2)->nullable()->after('commission_amount');

            $table->index(['apps_id', 'companies_id', 'commission_rate'], 'idx_orders_commission');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection('commerce')->table('orders', function (Blueprint $table) {
            $table->dropIndex('idx_orders_commission');
            $table->dropColumn(['commission_rate', 'commission_amount', 'provider_amount']);
        });
    }
};
