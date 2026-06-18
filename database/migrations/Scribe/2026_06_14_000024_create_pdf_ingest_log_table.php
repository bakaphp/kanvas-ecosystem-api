<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Audit trail for every inbound accounting PDF (email-driven ingest).
 *
 * One row per ingest event — created by ProcessAccountingPdfAction when a PDF arrives via the per-tenant
 * accounting mailbox. Captures:
 *   - The raw inbound metadata (message_id for dedup, from email for vendor fallback)
 *   - The LLM classification + extracted payload + confidence
 *   - The routing decision and the linked Scribe entity (if any was created)
 *   - The final status (entity_created / awaiting_bill_support / ignored / rejected / failed)
 *
 * Per the design call in PR 9: vendor invoices currently land here with status='awaiting_bill_support'
 * and NO linked entity — the Bill sub-ledger comes in PR 10, at which point those rows backfill.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::connection('accounting')->create('pdf_ingest_log', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('apps_id');
            $table->unsignedBigInteger('companies_id');
            $table->uuid('uuid')->unique();

            // Inbound metadata
            $table->unsignedBigInteger('filesystem_id');                         // logical FK → ecosystem.filesystem
            $table->string('message_id', 255)->nullable();                       // mailgun/postmark inbound id (dedup)
            $table->string('from_email', 255)->nullable();                       // sender → vendor-name fallback
            $table->string('from_name', 255)->nullable();
            $table->string('subject', 500)->nullable();
            $table->json('inbound_metadata')->nullable();                        // raw mailgun/postmark payload

            // Classification (set by ClassifyAndExtractFromPdfAction)
            $table->string('document_type', 32)
                ->default('unknown');                                            // expense_receipt | vendor_invoice | vendor_quote | our_invoice | our_quote | unknown
            $table->decimal('confidence', 4, 3)->default(0);                     // 0.000 – 1.000
            $table->json('extracted_payload')->nullable();                       // the LLM's structured output
            $table->text('classification_reasoning')->nullable();                // free-text why-this-type

            // Routing outcome
            $table->string('status', 32)
                ->default('pending');                                            // pending | entity_created | awaiting_bill_support | ignored_our_doc | quote_inbound_logged | rejected_unknown | failed
            $table->string('linked_entity_type', 64)->nullable();                // e.g. 'expense'
            $table->unsignedBigInteger('linked_entity_id')->nullable();          // id in the entity's table
            $table->text('rejected_reason')->nullable();                         // when status in (rejected_unknown, failed)

            // Audit
            $table->timestamp('processed_at')->nullable();
            $table->unsignedInteger('users_id')->nullable();
            $table->timestamps();

            $table->index(['apps_id', 'companies_id', 'status'], 'pdf_log_tenant_status_idx');
            $table->index(['apps_id', 'companies_id', 'document_type'], 'pdf_log_tenant_type_idx');
            $table->index('message_id', 'pdf_log_message_id_idx');
            $table->index(['linked_entity_type', 'linked_entity_id'], 'pdf_log_linked_entity_idx');
        });
    }

    public function down(): void
    {
        Schema::connection('accounting')->dropIfExists('pdf_ingest_log');
    }
};
