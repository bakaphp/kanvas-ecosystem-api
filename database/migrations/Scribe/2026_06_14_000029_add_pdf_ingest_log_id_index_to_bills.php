<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds an index on bills.pdf_ingest_log_id so the BackfillPdfIngestedBillsAction's
 *   "find every bill linked to this log row" reverse lookup hits an index.
 *
 * Forward link (pdf_ingest_log → bill) is already indexed via pdf_log_linked_entity_idx; this is
 * the back-link the BackfillAction uses to verify idempotency.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::connection('accounting')->table('bills', function (Blueprint $table) {
            $table->index('pdf_ingest_log_id', 'bills_pdf_ingest_log_id_idx');
        });
    }

    public function down(): void
    {
        Schema::connection('accounting')->table('bills', function (Blueprint $table) {
            $table->dropIndex('bills_pdf_ingest_log_id_idx');
        });
    }
};
