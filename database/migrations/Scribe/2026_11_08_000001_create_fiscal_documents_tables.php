<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Kanvas\Exceptions\ValidationException;

/**
 * Country-neutral fiscal documents (docs/accounting/fiscal-documents-table-plan.md). Every unique index starts
 * with the tenant, so an issuer's copy and a receiver's copy of the same document are two rows in two companies.
 */
return new class () extends Migration {
    private const array TABLES = [
        'fiscal_range_annulment_lines',
        'fiscal_range_annulments',
        'fiscal_number_ranges',
        'fiscal_document_relations',
        'fiscal_document_events',
        'fiscal_document_submissions',
        'fiscal_documents',
    ];

    public function up(): void
    {
        $schema = Schema::connection('accounting');

        $schema->create('fiscal_documents', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique('fd_uuid_uq');
            $table->unsignedInteger('apps_id');
            $table->unsignedInteger('companies_id');

            $table->string('entity_type', 100);
            $table->unsignedBigInteger('entity_id');
            $table->string('direction', 16);

            $table->char('country', 2);
            $table->string('provider', 32)->nullable();
            $table->string('environment', 16);
            $table->string('schema_version', 16)->nullable();

            $table->string('document_type', 16);
            $table->string('series', 16)->nullable();
            $table->string('number', 64);
            $table->unsignedSmallInteger('numbering_year')->nullable();
            $table->string('establishment', 32)->nullable();
            $table->string('point_of_sale', 32)->nullable();
            $table->string('issuer_tax_id', 32)->nullable();
            $table->string('receiver_tax_id', 32)->nullable();
            $table->string('identity_key', 191);
            $table->string('authority_id', 191)->nullable();
            $table->string('authority_seal', 255)->nullable();

            $table->unsignedBigInteger('fiscal_number_range_id')->nullable();

            $table->string('status', 32);
            $table->string('authority_status', 64)->nullable();
            $table->boolean('authority_number_consumed')->nullable();
            $table->timestamp('last_authority_update_at', 6)->nullable();
            $table->boolean('needs_review')->default(false);

            $table->string('current_key', 191)->nullable()->unique('fd_current_key_uq');
            $table->dateTime('issued_at')->nullable();
            $table->dateTime('signed_at')->nullable();
            $table->dateTime('authorized_at')->nullable();
            $table->dateTime('frozen_at')->nullable();
            $table->dateTime('submit_deadline_at')->nullable();
            $table->date('retain_until')->nullable();

            $table->char('currency', 3)->nullable();
            $table->decimal('exchange_rate', 20, 10)->nullable();
            $table->decimal('taxable_total', 18, 2)->nullable();
            $table->decimal('exempt_total', 18, 2)->nullable();
            $table->decimal('tax_total', 18, 2)->nullable();
            $table->decimal('total', 18, 2)->nullable();

            $table->json('regime_data')->nullable();
            $table->json('authority_data')->nullable();
            $table->timestamps();

            $table->unique(['companies_id', 'environment', 'direction', 'country', 'identity_key'], 'fd_identity_uq');
            $table->unique(['companies_id', 'environment', 'direction', 'country', 'authority_id'], 'fd_authority_uq');
            $table->index(['apps_id', 'companies_id', 'entity_type', 'entity_id'], 'fd_entity_idx');
            $table->index(['companies_id', 'document_type', 'number'], 'fd_number_idx');
            $table->index(['fiscal_number_range_id'], 'fd_range_idx');
        });

        $schema->create('fiscal_document_submissions', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('apps_id');
            $table->unsignedInteger('companies_id');
            $table->uuid('uuid')->unique('fds_uuid_uq');
            $table->unsignedBigInteger('fiscal_document_id');
            $table->unsignedInteger('attempt');
            $table->string('channel', 16)->default('sync');
            $table->string('status', 32);
            $table->string('provider', 32)->nullable();
            $table->string('provider_ref', 191)->nullable();
            $table->json('codes')->nullable();
            $table->json('messages')->nullable();
            $table->boolean('authority_number_consumed')->nullable();
            $table->dateTime('sent_at')->nullable();
            $table->dateTime('responded_at')->nullable();
            $table->unsignedBigInteger('request_file_id')->nullable();
            $table->char('request_sha256', 64)->nullable();
            $table->unsignedBigInteger('response_file_id')->nullable();
            $table->char('response_sha256', 64)->nullable();
            $table->timestamps();

            $table->unique(['fiscal_document_id', 'attempt'], 'fds_attempt_uq');
            $table->index(['companies_id', 'provider', 'provider_ref'], 'fds_provider_ref_idx');
        });

        $schema->create('fiscal_document_events', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('apps_id');
            $table->unsignedInteger('companies_id');
            $table->unsignedBigInteger('fiscal_document_id');
            $table->string('provider', 32)->default('');
            $table->string('event_type', 48);
            $table->string('regime_code', 32)->nullable();
            $table->string('direction', 16);
            $table->string('status', 32);
            $table->string('reason_code', 64)->nullable();
            $table->text('reason')->nullable();
            $table->unsignedBigInteger('replacement_fiscal_document_id')->nullable();
            $table->string('authority_id', 191)->nullable();
            $table->string('authority_seal', 255)->nullable();
            $table->dateTime('requested_at')->nullable();
            $table->dateTime('resolved_at')->nullable();
            $table->dateTime('deadline_at')->nullable();
            $table->json('payload')->nullable();
            $table->unsignedBigInteger('request_file_id')->nullable();
            $table->char('request_sha256', 64)->nullable();
            $table->unsignedBigInteger('response_file_id')->nullable();
            $table->char('response_sha256', 64)->nullable();
            $table->string('dedupe_key', 191);
            $table->dateTime('applied_at', 6)->nullable();
            $table->string('effect', 32)->nullable();
            $table->dateTime('effects_completed_at', 6)->nullable();
            $table->timestamps();

            $table->unique(['companies_id', 'provider', 'dedupe_key'], 'fde_dedupe_uq');
            $table->index(['fiscal_document_id', 'event_type'], 'fde_document_type_idx');
        });

        $schema->create('fiscal_document_relations', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('apps_id');
            $table->unsignedInteger('companies_id');
            $table->unsignedBigInteger('fiscal_document_id');
            $table->unsignedBigInteger('related_fiscal_document_id')->nullable();
            $table->string('related_reference', 191)->nullable();
            $table->date('related_issued_at')->nullable();
            $table->string('relation_type', 32);
            $table->string('regime_code', 32)->nullable();
            $table->json('amounts')->nullable();
            $table->timestamps();

            $table->index(['fiscal_document_id', 'relation_type'], 'fdr_document_idx');
            $table->index(['related_fiscal_document_id'], 'fdr_related_idx');
        });

        $schema->create('fiscal_number_ranges', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('apps_id');
            $table->unsignedInteger('companies_id');
            $table->string('environment', 16);
            $table->char('country', 2);
            $table->string('document_type', 16);
            $table->string('series', 16)->nullable();
            $table->unsignedBigInteger('range_from');
            $table->unsignedBigInteger('range_to');
            $table->unsignedTinyInteger('padding')->nullable();
            $table->dateTime('authorized_at')->nullable();
            $table->date('expires_at')->nullable();
            $table->string('authority_ref', 191)->nullable();
            $table->string('status', 16);
            $table->timestamps();

            $table->index(['companies_id', 'environment', 'country', 'document_type', 'status'], 'fnr_lookup_idx');
        });

        $schema->create('fiscal_range_annulments', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('apps_id');
            $table->unsignedInteger('companies_id');
            $table->string('environment', 16);
            $table->char('country', 2);
            $table->string('provider', 32)->nullable();
            $table->string('provider_ref', 191)->nullable();
            $table->string('status', 16);
            $table->dateTime('submitted_at')->nullable();
            $table->dateTime('resolved_at')->nullable();
            $table->json('payload')->nullable();
            $table->unsignedBigInteger('request_file_id')->nullable();
            $table->char('request_sha256', 64)->nullable();
            $table->unsignedBigInteger('response_file_id')->nullable();
            $table->char('response_sha256', 64)->nullable();
            $table->timestamps();

            $table->index(['companies_id', 'environment', 'country'], 'fra_company_idx');
        });

        $schema->create('fiscal_range_annulment_lines', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('fiscal_range_annulment_id');
            $table->string('document_type', 16);
            $table->string('series', 16)->nullable();
            $table->unsignedBigInteger('range_from');
            $table->unsignedBigInteger('range_to');
            $table->string('result', 16)->default('pending');
            $table->text('message')->nullable();
            $table->timestamps();

            $table->index(['fiscal_range_annulment_id'], 'fral_annulment_idx');
        });
    }

    /**
     * Production fiscal rows are tax records; a rollback must never drop them.
     */
    public function down(): void
    {
        $schema = Schema::connection('accounting');

        if ($schema->hasTable('fiscal_documents')
            && DB::connection('accounting')->table('fiscal_documents')->where('environment', 'production')->exists()) {
            throw new ValidationException('fiscal_documents holds production rows; refusing to drop the fiscal tables.');
        }

        foreach (self::TABLES as $table) {
            $schema->dropIfExists($table);
        }
    }
};
