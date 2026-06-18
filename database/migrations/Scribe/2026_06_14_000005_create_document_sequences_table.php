<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::connection('accounting')->create('document_sequences', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('apps_id');
            $table->unsignedInteger('companies_id');

            $table->enum('document_type', [
                'invoice',
                'credit_note',
                'quote',
                'bill',
                'vendor_credit',
                'sales_receipt',
                'expense',
                'journal_entry',
            ]);

            $table->string('prefix', 32)->default('');
            $table->unsignedBigInteger('next_value')->default(1);

            $table->timestamps();

            $table->unique(['apps_id', 'companies_id', 'document_type'], 'doc_seq_app_company_type_uq');
        });
    }

    public function down(): void
    {
        Schema::connection('accounting')->dropIfExists('document_sequences');
    }
};
