<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        if (! Schema::connection('accounting')->hasColumn('invoices', 'paid_at')) {
            Schema::connection('accounting')->table('invoices', function (Blueprint $table): void {
                $table->dateTime('paid_at')->nullable()->after('balance_due_base');
                $table->index(['apps_id', 'companies_id', 'paid_at'], 'invoices_app_company_paid_at_idx');
            });
        }

        if (! Schema::connection('accounting')->hasColumn('bills', 'paid_at')) {
            Schema::connection('accounting')->table('bills', function (Blueprint $table): void {
                $table->dateTime('paid_at')->nullable()->after('balance_due_base');
                $table->index(['apps_id', 'companies_id', 'paid_at'], 'bills_app_company_paid_at_idx');
            });
        }

        // Backfill from MAX(allocations.allocated_at) for currently-PAID rows. Cheap one-shot.
        DB::connection('accounting')->update(<<<'SQL'
            UPDATE invoices i
            JOIN (
                SELECT invoice_id, MAX(allocated_at) AS max_allocated
                FROM invoice_payment_allocations
                WHERE status = 'active'
                GROUP BY invoice_id
            ) a ON a.invoice_id = i.id
            SET i.paid_at = a.max_allocated
            WHERE i.document_status = 'paid' AND i.paid_at IS NULL
        SQL);

        DB::connection('accounting')->update(<<<'SQL'
            UPDATE bills b
            JOIN (
                SELECT bill_id, MAX(allocated_at) AS max_allocated
                FROM bill_payment_allocations
                WHERE status = 'active'
                GROUP BY bill_id
            ) a ON a.bill_id = b.id
            SET b.paid_at = a.max_allocated
            WHERE b.document_status = 'paid' AND b.paid_at IS NULL
        SQL);
    }

    public function down(): void
    {
        if (Schema::connection('accounting')->hasColumn('invoices', 'paid_at')) {
            Schema::connection('accounting')->table('invoices', function (Blueprint $table): void {
                $table->dropIndex('invoices_app_company_paid_at_idx');
                $table->dropColumn('paid_at');
            });
        }
        if (Schema::connection('accounting')->hasColumn('bills', 'paid_at')) {
            Schema::connection('accounting')->table('bills', function (Blueprint $table): void {
                $table->dropIndex('bills_app_company_paid_at_idx');
                $table->dropColumn('paid_at');
            });
        }
    }
};
