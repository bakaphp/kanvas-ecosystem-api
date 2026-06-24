<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 4 — drop polymorphic billable/vendor_billable in favour of a direct FK to Guild Organization.
 *
 * Rule: every Scribe transaction's legal counterparty is ALWAYS a Guild Organization. Storing a
 * `*_type` discriminator was dead weight — the column never held anything but `'organization'`.
 * Replacing with a single FK column simplifies the schema, lets Eloquent use BelongsTo/HasMany
 * (instead of MorphTo/MorphMany), and removes the morphMap registration.
 *
 * The `contact_people_id` on quotes (added in PR 2b) stays — that's the *recipient* (a human at
 * the customer Org), not the legal counterparty.
 *
 * Tables touched:
 *   - invoices.billable_*           → customer_organization_id
 *   - quotes.billable_*             → customer_organization_id
 *   - sales_receipts.billable_*     → customer_organization_id
 *   - bills.vendor_billable_*       → vendor_organization_id
 *   - expenses.vendor_billable_*    → vendor_organization_id
 *
 * No data preservation — Scribe is not in production yet and the test DBs get truncated between
 * the polymorphism-on suite and the post-Phase-4 suite. Down() restores the polymorphic shape.
 */
return new class () extends Migration {
    public function up(): void
    {
        // ── Invoices ──
        Schema::connection('accounting')->table('invoices', function (Blueprint $table): void {
            $table->dropIndex('invoices_app_company_billable_idx');
            $table->dropColumn(['billable_type', 'billable_id']);
        });
        Schema::connection('accounting')->table('invoices', function (Blueprint $table): void {
            $table->unsignedBigInteger('customer_organization_id')->nullable()->after('uuid');
            $table->index(
                ['apps_id', 'companies_id', 'customer_organization_id'],
                'invoices_app_company_customer_idx',
            );
        });

        // ── Quotes ──
        Schema::connection('accounting')->table('quotes', function (Blueprint $table): void {
            $table->dropIndex('quotes_app_company_billable_idx');
            $table->dropColumn(['billable_type', 'billable_id']);
        });
        Schema::connection('accounting')->table('quotes', function (Blueprint $table): void {
            $table->unsignedBigInteger('customer_organization_id')->nullable()->after('uuid');
            $table->index(
                ['apps_id', 'companies_id', 'customer_organization_id'],
                'quotes_app_company_customer_idx',
            );
        });

        // ── SalesReceipts ──
        Schema::connection('accounting')->table('sales_receipts', function (Blueprint $table): void {
            $table->dropIndex('sr_app_company_billable_idx');
            $table->dropColumn(['billable_type', 'billable_id']);
        });
        Schema::connection('accounting')->table('sales_receipts', function (Blueprint $table): void {
            $table->unsignedBigInteger('customer_organization_id')->nullable()->after('uuid');
            $table->index(
                ['apps_id', 'companies_id', 'customer_organization_id'],
                'sr_app_company_customer_idx',
            );
        });

        // ── Bills (also drop the compound unique that included vendor_billable_*) ──
        Schema::connection('accounting')->table('bills', function (Blueprint $table): void {
            $table->dropIndex('bills_app_company_vendor_idx');
            $table->dropColumn(['vendor_billable_type', 'vendor_billable_id']);
        });
        Schema::connection('accounting')->table('bills', function (Blueprint $table): void {
            $table->unsignedBigInteger('vendor_organization_id')->nullable()->after('uuid');
            $table->index(
                ['apps_id', 'companies_id', 'vendor_organization_id'],
                'bills_app_company_vendor_idx',
            );
        });

        // ── Expenses ──
        Schema::connection('accounting')->table('expenses', function (Blueprint $table): void {
            $table->dropIndex('exp_vendor_billable_idx');
            $table->dropColumn(['vendor_billable_type', 'vendor_billable_id']);
        });
        Schema::connection('accounting')->table('expenses', function (Blueprint $table): void {
            $table->unsignedBigInteger('vendor_organization_id')->nullable()->after('uuid');
            $table->index(
                ['vendor_organization_id'],
                'exp_vendor_org_idx',
            );
        });
    }

    public function down(): void
    {
        Schema::connection('accounting')->table('invoices', function (Blueprint $table): void {
            $table->dropIndex('invoices_app_company_customer_idx');
            $table->dropColumn('customer_organization_id');
        });
        Schema::connection('accounting')->table('invoices', function (Blueprint $table): void {
            $table->string('billable_type', 64)->nullable()->after('uuid');
            $table->unsignedBigInteger('billable_id')->nullable()->after('billable_type');
            $table->index(
                ['apps_id', 'companies_id', 'billable_type', 'billable_id'],
                'invoices_app_company_billable_idx',
            );
        });

        Schema::connection('accounting')->table('quotes', function (Blueprint $table): void {
            $table->dropIndex('quotes_app_company_customer_idx');
            $table->dropColumn('customer_organization_id');
        });
        Schema::connection('accounting')->table('quotes', function (Blueprint $table): void {
            $table->string('billable_type', 64)->nullable()->after('uuid');
            $table->unsignedBigInteger('billable_id')->nullable()->after('billable_type');
            $table->index(
                ['apps_id', 'companies_id', 'billable_type', 'billable_id'],
                'quotes_app_company_billable_idx',
            );
        });

        Schema::connection('accounting')->table('sales_receipts', function (Blueprint $table): void {
            $table->dropIndex('sr_app_company_customer_idx');
            $table->dropColumn('customer_organization_id');
        });
        Schema::connection('accounting')->table('sales_receipts', function (Blueprint $table): void {
            $table->string('billable_type', 64)->nullable()->after('uuid');
            $table->unsignedBigInteger('billable_id')->nullable()->after('billable_type');
            $table->index(
                ['apps_id', 'companies_id', 'billable_type', 'billable_id'],
                'sr_app_company_billable_idx',
            );
        });

        Schema::connection('accounting')->table('bills', function (Blueprint $table): void {
            $table->dropIndex('bills_app_company_vendor_idx');
            $table->dropColumn('vendor_organization_id');
        });
        Schema::connection('accounting')->table('bills', function (Blueprint $table): void {
            $table->string('vendor_billable_type', 64)->nullable()->after('uuid');
            $table->unsignedBigInteger('vendor_billable_id')->nullable()->after('vendor_billable_type');
            $table->index(
                ['apps_id', 'companies_id', 'vendor_billable_type', 'vendor_billable_id'],
                'bills_app_company_vendor_idx',
            );
        });

        Schema::connection('accounting')->table('expenses', function (Blueprint $table): void {
            $table->dropIndex('exp_vendor_org_idx');
            $table->dropColumn('vendor_organization_id');
        });
        Schema::connection('accounting')->table('expenses', function (Blueprint $table): void {
            $table->string('vendor_billable_type', 64)->nullable()->after('uuid');
            $table->unsignedBigInteger('vendor_billable_id')->nullable()->after('vendor_billable_type');
            $table->index(
                ['vendor_billable_type', 'vendor_billable_id'],
                'exp_vendor_billable_idx',
            );
        });
    }
};
