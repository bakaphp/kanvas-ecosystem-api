<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Purchase orders are a read-mirror from the source ERP, used by the AP-bill agent to match an
 * incoming vendor invoice to its PO (and inherit the PO line's GL coding). Not a posted accounting
 * document — no JE, no state machine — just reference data the agent queries.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::connection('accounting')->create('purchase_orders', function (Blueprint $table): void {
            $table->id();
            $table->unsignedInteger('apps_id');
            $table->unsignedInteger('companies_id');
            $table->uuid('uuid');

            $table->string('order_type', 10);
            $table->string('order_number', 40);

            $table->unsignedBigInteger('vendor_organization_id')->nullable();
            $table->string('vendor_code', 64)->nullable();

            $table->string('status', 16)->nullable();
            $table->date('order_date')->nullable();
            $table->string('currency', 3)->nullable();
            $table->decimal('order_total', 18, 4)->default(0);

            $table->string('source', 32)->default('kanvas');
            $table->string('external_id', 191)->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->json('metadata')->nullable();

            $table->boolean('is_deleted')->default(false);
            $table->unsignedInteger('users_id')->nullable();
            $table->timestamps();

            $table->unique(['apps_id', 'companies_id', 'order_type', 'order_number'], 'po_app_company_type_number_uq');
            $table->unique(['apps_id', 'source', 'external_id'], 'po_app_source_external_uq');
            $table->index(['apps_id', 'companies_id', 'vendor_organization_id', 'status'], 'po_app_company_vendor_status_idx');
            $table->index(['apps_id', 'companies_id', 'vendor_code'], 'po_app_company_vendorcode_idx');
        });
    }

    public function down(): void
    {
        Schema::connection('accounting')->dropIfExists('purchase_orders');
    }
};
