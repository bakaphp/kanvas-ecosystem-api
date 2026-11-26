<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The third gate: Operations releases the original bill of lading, or the telex release, only to a customer inside
 * their credit limit and without overdue invoices.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::connection('accounting')->table('sales_order_shipments', function (Blueprint $table): void {
            $table->dateTime('documents_released_at')->nullable()->after('documents_package_sent_at');

            $table->index(['apps_id', 'companies_id', 'documents_released_at'], 'sos_app_company_released_idx');
        });
    }

    public function down(): void
    {
        Schema::connection('accounting')->table('sales_order_shipments', function (Blueprint $table): void {
            $table->dropIndex('sos_app_company_released_idx');
            $table->dropColumn('documents_released_at');
        });
    }
};
