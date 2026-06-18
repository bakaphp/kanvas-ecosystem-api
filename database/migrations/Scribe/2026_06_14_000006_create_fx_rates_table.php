<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::connection('accounting')->create('fx_rates', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('apps_id');

            $table->string('base_currency', 3);
            $table->string('quote_currency', 3);
            $table->decimal('rate', 20, 10);
            $table->date('rate_date');
            $table->string('source', 32)->default('manual');     // banco_central_do | open_exchange_rates | manual | ...

            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['apps_id', 'base_currency', 'quote_currency', 'rate_date'], 'fx_rates_app_pair_date_uq');
            $table->index(['apps_id', 'base_currency', 'rate_date'], 'fx_rates_app_base_date_idx');
        });
    }

    public function down(): void
    {
        Schema::connection('accounting')->dropIfExists('fx_rates');
    }
};
