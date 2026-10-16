<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::connection('commerce')->create('shipping_rate_card_rates', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('rate_card_id');
            $table->string('zone', 32);
            $table->unsignedInteger('max_grams');
            $table->decimal('amount', 10, 2);
            $table->unsignedSmallInteger('transit_min_days')->nullable();
            $table->unsignedSmallInteger('transit_max_days')->nullable();
            $table->boolean('is_deleted')->default(0);
            $table->timestamps();

            $table->foreign('rate_card_id')->references('id')->on('shipping_rate_cards')->onDelete('cascade');
            $table->unique(['rate_card_id', 'zone', 'max_grams'], 'shipping_rate_card_rates_unique');
        });
    }

    public function down(): void
    {
        Schema::connection('commerce')->dropIfExists('shipping_rate_card_rates');
    }
};
