<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Once Operations has accepted a sales order, its shipping documents are cross-checked, the bill of lading is
 * recorded, and the invoice falls due a set number of days after the bill of lading date, not after the invoice date.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::connection('accounting')->table('sales_orders', function (Blueprint $table): void {
            $table->unsignedBigInteger('invoice_id')->nullable()->after('quote_id');
            $table->json('documents_check')->nullable()->after('reviewed_at');
            $table->dateTime('documents_checked_at')->nullable()->after('documents_check');

            $table->index(['apps_id', 'companies_id', 'invoice_id'], 'so_app_company_invoice_idx');
        });

        Schema::connection('accounting')->create('sales_order_shipments', function (Blueprint $table): void {
            $table->id();
            $table->unsignedInteger('apps_id');
            $table->unsignedInteger('companies_id');
            $table->unsignedBigInteger('sales_order_id');

            $table->string('bl_number', 64);
            $table->date('bl_date');
            $table->string('vessel', 191)->nullable();
            $table->json('containers')->nullable();

            $table->unsignedSmallInteger('payment_terms_days');
            $table->date('due_date');

            $table->date('documents_package_due_on')->nullable();
            $table->dateTime('documents_package_sent_at')->nullable();

            $table->date('delivered_on')->nullable();
            $table->string('pod_reference', 191)->nullable();
            $table->text('delivery_discrepancies')->nullable();
            $table->boolean('damage_reported')->default(false);
            $table->dateTime('delivery_recorded_at')->nullable();

            $table->dateTime('recorded_at');
            $table->unsignedInteger('users_id')->nullable();
            $table->timestamps();

            $table->unique('sales_order_id', 'sos_order_uq');
            $table->index(['apps_id', 'companies_id', 'bl_number'], 'sos_app_company_bl_idx');
            $table->index(['apps_id', 'companies_id', 'bl_date'], 'sos_app_company_bl_date_idx');
            $table->index(['apps_id', 'companies_id', 'due_date'], 'sos_app_company_due_date_idx');
            $table->index(['apps_id', 'companies_id', 'documents_package_due_on'], 'sos_app_company_package_due_idx');
            $table->index(['apps_id', 'companies_id', 'delivered_on'], 'sos_app_company_delivered_idx');
        });
    }

    public function down(): void
    {
        Schema::connection('accounting')->dropIfExists('sales_order_shipments');

        Schema::connection('accounting')->table('sales_orders', function (Blueprint $table): void {
            $table->dropIndex('so_app_company_invoice_idx');
            $table->dropColumn(['invoice_id', 'documents_check', 'documents_checked_at']);
        });
    }
};
