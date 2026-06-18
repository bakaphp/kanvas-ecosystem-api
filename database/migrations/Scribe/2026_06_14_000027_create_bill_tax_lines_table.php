<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::connection('accounting')->create('bill_tax_lines', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('bill_id');
            $table->unsignedBigInteger('tax_code_id')->nullable();
            $table->string('name', 191);
            $table->decimal('tax_rate', 10, 6)->nullable();
            $table->string('jurisdiction', 32)->nullable();

            $table->decimal('tax_amount_native', 18, 4)->default(0);
            $table->decimal('tax_amount_base', 18, 4)->default(0);

            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['bill_id'], 'bill_tax_lines_bill_idx');
        });
    }

    public function down(): void
    {
        Schema::connection('accounting')->dropIfExists('bill_tax_lines');
    }
};
