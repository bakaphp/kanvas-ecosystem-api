<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::connection('commerce')->create('order_providers', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('order_id');
            $table->unsignedBigInteger('company_id');
            $table->timestamps();

            $table->unique(['order_id', 'company_id'], 'unique_order_company');
            $table->index('order_id', 'idx_order_id');
            $table->index('company_id', 'idx_company_id');

            // Foreign key for order_id (same database)
            $table->foreign('order_id')->references('id')->on('orders')->onDelete('cascade');
            // Note: No FK for company_id - companies table is in different database (ecosystem)
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection('commerce')->dropIfExists('order_providers');
    }
};
