<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds content_sha256 column for PDF-content-hash dedup (PR 9.1 — Path 2).
 *
 * Indexed on (apps_id, companies_id, content_sha256) so the orchestrator's "have we already seen
 * this exact PDF?" lookup is a single-row hash hit.
 *
 * Backfill: existing rows have NULL content_sha256 (pre-9.1 ingests stay non-deduplicated, which is
 * the conservative default — we'd rather double-process a few historicals than miss a real ingest).
 * The orchestrator handles NULL by treating it as "not seen yet" for matching purposes.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::connection('accounting')->table('pdf_ingest_log', function (Blueprint $table) {
            $table->string('content_sha256', 64)->nullable()->after('filesystem_id');
            $table->index(['apps_id', 'companies_id', 'content_sha256'], 'pdf_log_tenant_hash_idx');
        });
    }

    public function down(): void
    {
        Schema::connection('accounting')->table('pdf_ingest_log', function (Blueprint $table) {
            $table->dropIndex('pdf_log_tenant_hash_idx');
            $table->dropColumn('content_sha256');
        });
    }
};
