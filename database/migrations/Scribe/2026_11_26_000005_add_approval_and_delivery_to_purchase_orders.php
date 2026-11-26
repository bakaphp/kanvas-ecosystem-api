<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An order the company issues is approved before it is sent, and what was sent to whom is kept. Staff notes get
 * their own column: `notes` prints on the document the vendor receives.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::connection('accounting')->table('purchase_orders', function (Blueprint $table): void {
            $table->dateTime('approved_at')->nullable()->after('expected_date');
            $table->dateTime('sent_at')->nullable()->after('approved_at');
            $table->string('sent_to', 500)->nullable()->after('sent_at');
            $table->text('internal_notes')->nullable()->after('notes');

            $table->index(['apps_id', 'companies_id', 'approved_at'], 'po_app_company_approved_idx');
            $table->index(['apps_id', 'companies_id', 'sent_at'], 'po_app_company_sent_idx');
            // Answers "sent to this address" when it is the only or the first recipient; an address further
            // down the list is a LIKE scan.
            $table->index(['apps_id', 'companies_id', 'sent_to'], 'po_app_company_sent_to_idx');
            $table->index(['apps_id', 'companies_id', 'cancelled_at'], 'po_app_company_cancelled_idx');
        });
    }

    public function down(): void
    {
        Schema::connection('accounting')->table('purchase_orders', function (Blueprint $table): void {
            $table->dropIndex('po_app_company_approved_idx');
            $table->dropIndex('po_app_company_sent_idx');
            $table->dropIndex('po_app_company_sent_to_idx');
            $table->dropIndex('po_app_company_cancelled_idx');
            $table->dropColumn(['approved_at', 'sent_at', 'sent_to', 'internal_notes']);
        });
    }
};
