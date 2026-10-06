<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::connection('commerce')->create('shipping_rate_card_countries', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('companies_id');
            $table->unsignedBigInteger('apps_id');
            $table->string('provider', 64);
            $table->char('country_code', 2);
            $table->string('zone', 32);
            $table->boolean('is_deleted')->default(0);
            $table->timestamps();

            $table->unique(['companies_id', 'apps_id', 'provider', 'country_code'], 'shipping_rate_card_countries_unique');
        });
    }

    public function down(): void
    {
        Schema::connection('commerce')->dropIfExists('shipping_rate_card_countries');
    }
};
