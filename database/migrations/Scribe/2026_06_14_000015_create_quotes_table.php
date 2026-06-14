<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::connection('accounting')->create('quotes', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('apps_id');
            $table->unsignedInteger('companies_id');
            $table->uuid('uuid');

            $table->string('quote_number', 64)->nullable();

            // Polymorphic billable (Organization|People) — same shape as invoices
            $table->string('billable_type', 64)->nullable();
            $table->unsignedBigInteger('billable_id')->nullable();

            // Billable snapshot (immutable post-send, per plan §7.4)
            $table->string('billable_display_name', 191)->nullable();
            $table->string('billable_legal_name', 191)->nullable();
            $table->string('billable_tax_id', 64)->nullable();
            $table->string('billable_email', 191)->nullable();
            $table->json('billing_address_snapshot')->nullable();

            // Status
            $table->enum('status', [
                'draft', 'sent', 'accepted', 'rejected', 'expired', 'converted', 'superseded',
            ])->default('draft');

            // Lifecycle dates
            $table->date('issued_date')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->date('valid_until')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->string('lost_reason', 191)->nullable();

            // Document links
            $table->unsignedBigInteger('converted_to_invoice_id')->nullable();

            // Revision chain (Round-6)
            $table->unsignedBigInteger('parent_quote_id')->nullable();
            $table->unsignedSmallInteger('revision_number')->default(1);

            // Tax + delivery (consistency with invoices)
            $table->enum('tax_calculation_mode', ['exclusive', 'inclusive', 'not_applicable'])->default('exclusive');
            $table->enum('delivery_status', ['not_applicable', 'needs_send', 'sent', 'bounced', 'opened'])->default('not_applicable');

            // Currency + FX
            $table->string('currency', 3);
            $table->decimal('fx_rate_to_base', 20, 10);
            $table->timestamp('fx_rate_at')->nullable();

            // Native amounts
            $table->decimal('subtotal_native', 18, 4)->default(0);
            $table->decimal('tax_native',      18, 4)->default(0);
            $table->decimal('discount_native', 18, 4)->default(0);
            $table->decimal('total_native',    18, 4)->default(0);

            // Base amounts
            $table->decimal('subtotal_base', 18, 4)->default(0);
            $table->decimal('tax_base',      18, 4)->default(0);
            $table->decimal('discount_base', 18, 4)->default(0);
            $table->decimal('total_base',    18, 4)->default(0);

            $table->json('regional_compliance')->nullable();

            $table->text('notes')->nullable();           // customer-facing
            $table->text('internal_notes')->nullable();   // private
            $table->text('terms')->nullable();

            // Source + anti-loop
            $table->enum('source', ['kanvas', 'adm_cloud', 'manual'])->default('kanvas');
            $table->string('external_id', 191)->nullable();
            $table->string('external_url', 500)->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->enum('origin', ['external', 'kanvas'])->default('kanvas');

            $table->json('metadata')->nullable();

            $table->boolean('is_deleted')->default(false);
            $table->unsignedInteger('users_id')->nullable();
            $table->timestamps();

            // Uniqueness
            $table->unique(['apps_id', 'companies_id', 'quote_number'], 'quotes_app_company_number_uq');
            $table->unique(['apps_id', 'source', 'external_id'], 'quotes_app_source_external_uq');

            $table->index(['apps_id', 'companies_id', 'status'], 'quotes_app_company_status_idx');
            $table->index(['apps_id', 'companies_id', 'billable_type', 'billable_id'], 'quotes_app_company_billable_idx');
            $table->index(['parent_quote_id'], 'quotes_parent_idx');
            $table->index(['uuid'], 'quotes_uuid_idx');
        });
    }

    public function down(): void
    {
        Schema::connection('accounting')->dropIfExists('quotes');
    }
};
