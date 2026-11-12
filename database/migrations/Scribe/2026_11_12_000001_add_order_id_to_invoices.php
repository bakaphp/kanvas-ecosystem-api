<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        if (Schema::connection('accounting')->hasColumn('invoices', 'order_id')) {
            return;
        }

        Schema::connection('accounting')->table('invoices', function (Blueprint $table): void {
            // Souk orders live on the commerce connection, so no DB-level foreign key.
            $table->unsignedBigInteger('order_id')->nullable()->after('quote_id');
            $table->index(['apps_id', 'companies_id', 'order_id'], 'invoices_app_company_order_idx');
        });
    }

    public function down(): void
    {
        if (! Schema::connection('accounting')->hasColumn('invoices', 'order_id')) {
            return;
        }

        Schema::connection('accounting')->table('invoices', function (Blueprint $table): void {
            $table->dropIndex('invoices_app_company_order_idx');
            $table->dropColumn('order_id');
        });
    }
};
