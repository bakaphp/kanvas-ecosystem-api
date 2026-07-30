<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Register `pending_approval` on bills.document_status so the Kanvas-first approval flow
 * (DRAFT → PENDING_APPROVAL → RECEIVED) can park an agent-proposed bill before it hits the books.
 * The direct DRAFT → RECEIVED path is unchanged.
 */
return new class () extends Migration {
    public function up(): void
    {
        DB::connection('accounting')->statement(
            "ALTER TABLE bills MODIFY document_status "
            . "ENUM('draft','pending_approval','received','paid','voided') NOT NULL DEFAULT 'draft'"
        );
    }

    public function down(): void
    {
        DB::connection('accounting')->statement(
            "ALTER TABLE bills MODIFY document_status "
            . "ENUM('draft','received','paid','voided') NOT NULL DEFAULT 'draft'"
        );
    }
};
