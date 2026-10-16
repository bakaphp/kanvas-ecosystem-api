<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::connection('commerce')->create('shipping_rate_cards', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('companies_id');
            $table->unsignedBigInteger('apps_id');
            $table->string('provider', 64);
            $table->string('service_code', 64);
            $table->string('name');
            $table->char('currency', 3);
            $table->decimal('fixed_charge', 10, 2)->default(0);
            $table->boolean('is_deleted')->default(0);
            $table->timestamps();

            $table->unique(['companies_id', 'apps_id', 'provider', 'service_code'], 'shipping_rate_cards_unique');
        });
    }

    public function down(): void
    {
        Schema::connection('commerce')->dropIfExists('shipping_rate_cards');
    }
};
