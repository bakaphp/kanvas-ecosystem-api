<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::connection('accounting')->create('tax_rates', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tax_code_id');

            $table->string('name', 191);
            $table->decimal('rate', 10, 6);                          // e.g. 0.180000 for 18%

            $table->unsignedBigInteger('tax_account_id')->nullable();   // GL account that collects this payable

            $table->unsignedSmallInteger('sort_order')->default(0);

            $table->date('effective_from');
            $table->date('effective_to')->nullable();

            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['tax_code_id', 'effective_from'], 'tax_rates_code_effective_idx');
            $table->index(['tax_account_id'], 'tax_rates_account_idx');
        });
    }

    public function down(): void
    {
        Schema::connection('accounting')->dropIfExists('tax_rates');
    }
};
