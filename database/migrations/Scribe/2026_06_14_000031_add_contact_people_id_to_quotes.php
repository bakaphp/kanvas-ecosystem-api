<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds `contact_people_id` to `quotes`.
 *
 * The legal counterparty on a quote is always an Organization (the `billable_id` polymorphic FK).
 * `contact_people_id` is the *human at that organization* the quote was actually addressed to —
 * the "Attn: John Doe" recipient. Optional; B2B quotes that don't single out an individual leave
 * it null.
 *
 * Not a foreign key to peoples because peoples lives in the `crm` connection and quotes lives in
 * `accounting` (cross-DB FKs aren't enforceable). The application-level invariant (must be a real
 * Guild People row for this tenant) is enforced in CreateQuoteAction.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::connection('accounting')->table('quotes', function (Blueprint $table): void {
            $table->unsignedBigInteger('contact_people_id')->nullable()->after('billable_id');
            $table->index(
                ['apps_id', 'companies_id', 'contact_people_id'],
                'quotes_app_company_contact_idx',
            );
        });
    }

    public function down(): void
    {
        Schema::connection('accounting')->table('quotes', function (Blueprint $table): void {
            $table->dropIndex('quotes_app_company_contact_idx');
            $table->dropColumn('contact_people_id');
        });
    }
};
