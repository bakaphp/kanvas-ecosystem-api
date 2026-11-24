<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The customer's acceptance of one quote revision: who signed, what they signed (SOW revision and its
 * hash) and when. Append-only; one row per (quote, revision), so a second accept never rewrites the signer.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::connection('accounting')->create('document_acceptances', function (Blueprint $table): void {
            $table->id();
            $table->unsignedInteger('apps_id');
            $table->unsignedInteger('companies_id');
            $table->unsignedBigInteger('quote_id');
            $table->unsignedInteger('quote_revision');
            $table->unsignedBigInteger('sow_message_id')->nullable();
            $table->char('sow_hash', 64)->nullable();
            $table->unsignedBigInteger('engagement_id')->nullable();
            $table->unsignedBigInteger('people_id')->nullable();
            $table->unsignedInteger('users_id')->nullable();
            $table->string('signer_name', 191)->nullable();
            $table->string('signer_email', 191)->nullable();
            $table->unsignedBigInteger('signature_filesystem_id')->nullable();
            $table->timestamp('accepted_at');
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 512)->nullable();
            $table->timestamp('created_at')->nullable();

            $table->unique(['quote_id', 'quote_revision'], 'document_acceptances_quote_revision_unique');
            $table->index(['apps_id', 'companies_id', 'accepted_at'], 'document_acceptances_company_idx');
        });
    }

    public function down(): void
    {
        Schema::connection('accounting')->dropIfExists('document_acceptances');
    }
};
