<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Indexes for what the purchase order list and the agent tools filter and sort by. The unique index on the
 * number leads with `order_type`, so a lookup by number alone could not use it.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::connection('accounting')->table('purchase_orders', function (Blueprint $table): void {
            $table->index(['apps_id', 'companies_id', 'order_number'], 'po_app_company_number_idx');
            $table->index(['apps_id', 'companies_id', 'order_date'], 'po_app_company_order_date_idx');
            $table->index(['apps_id', 'companies_id', 'expected_date'], 'po_app_company_expected_date_idx');
            $table->index(['uuid'], 'po_uuid_idx');
        });
    }

    public function down(): void
    {
        Schema::connection('accounting')->table('purchase_orders', function (Blueprint $table): void {
            $table->dropIndex('po_app_company_number_idx');
            $table->dropIndex('po_app_company_order_date_idx');
            $table->dropIndex('po_app_company_expected_date_idx');
            $table->dropIndex('po_uuid_idx');
        });
    }
};
