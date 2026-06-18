<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds `payment_status_hint` to `bills`. Operator-UI hint for "this PDF says it was already paid"
 * — drives the "Mark as paid?" suggestion on PDF-ingested drafts. Not a GL state column.
 *
 * - 'paid'    — LLM saw a PAID stamp / receipt section / payment history table
 * - 'unpaid'  — LLM saw a future due date with no payment evidence
 * - 'unknown' — default; LLM didn't determine, OR the bill wasn't PDF-ingested
 *
 * Does NOT change `document_status` semantics — the authoritative payment state still lives
 * there. This column never auto-advances the lifecycle.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::connection('accounting')->table('bills', function (Blueprint $table): void {
            $table->string('payment_status_hint', 16)->default('unknown')->after('document_status');
            $table->index(
                ['apps_id', 'companies_id', 'payment_status_hint'],
                'bills_app_company_payment_hint_idx',
            );
        });
    }

    public function down(): void
    {
        Schema::connection('accounting')->table('bills', function (Blueprint $table): void {
            $table->dropIndex('bills_app_company_payment_hint_idx');
            $table->dropColumn('payment_status_hint');
        });
    }
};
