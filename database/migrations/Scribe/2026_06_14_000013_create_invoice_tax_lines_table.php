<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Structured tax tracking parallel to invoice_lines.
 *
 * Each row represents a single tax application on an invoice (e.g. one row for ITBIS 18%, another for ISR 10%
 * if the invoice has withholding). Lets the agent answer "what ITBIS did MCTekk DR collect in Q1" without parsing
 * the verbatim tax_metadata json.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::connection('accounting')->create('invoice_tax_lines', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('invoice_id');
            $table->unsignedBigInteger('tax_code_id')->nullable();

            $table->string('name', 191);                           // e.g. "ITBIS 18%" — snapshotted from the tax code at issue
            $table->decimal('tax_rate', 10, 6);
            $table->string('jurisdiction', 32)->nullable();

            $table->decimal('tax_amount_native', 18, 4)->default(0);
            $table->decimal('tax_amount_base', 18, 4)->default(0);

            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['invoice_id'], 'invoice_tax_lines_invoice_idx');
            $table->index(['tax_code_id'], 'invoice_tax_lines_tax_code_idx');
        });
    }

    public function down(): void
    {
        Schema::connection('accounting')->dropIfExists('invoice_tax_lines');
    }
};
