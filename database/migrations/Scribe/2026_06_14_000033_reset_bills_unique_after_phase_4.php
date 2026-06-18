<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 4 cleanup — the `bills_vendor_number_uq` constraint that was originally
 * `(apps_id, companies_id, vendor_billable_type, vendor_billable_id, bill_number)` got auto-shrunk
 * by MySQL when migration #032 dropped its `vendor_billable_*` columns. It now reads
 * `(apps_id, companies_id, bill_number)`, which is over-restrictive — two different vendors can
 * legally issue invoices with identical bill_numbers.
 *
 * Restore the intended semantics with the new `vendor_organization_id` column.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::connection('accounting')->table('bills', function (Blueprint $table): void {
            $table->dropUnique('bills_vendor_number_uq');
        });
        Schema::connection('accounting')->table('bills', function (Blueprint $table): void {
            $table->unique(
                ['apps_id', 'companies_id', 'vendor_organization_id', 'bill_number'],
                'bills_vendor_number_uq',
            );
        });
    }

    public function down(): void
    {
        Schema::connection('accounting')->table('bills', function (Blueprint $table): void {
            $table->dropUnique('bills_vendor_number_uq');
        });
        Schema::connection('accounting')->table('bills', function (Blueprint $table): void {
            $table->unique(
                ['apps_id', 'companies_id', 'bill_number'],
                'bills_vendor_number_uq',
            );
        });
    }
};
