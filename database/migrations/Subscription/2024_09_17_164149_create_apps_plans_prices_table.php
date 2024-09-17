<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('apps_plans_prices', function (Blueprint $table) {
            $table->integer('id', true);
            $table->integer('apps_plans_id')->nullable()->index('apps_plans_id');
            $table->string('stripe_id', 100)->nullable()->index('stripe_id');
            $table->decimal('amount', 10);
            $table->string('currency', 3);
            $table->string('interval');
            $table->integer('is_default')->nullable()->default(0)->index('is_default');
            $table->date('created_at')->nullable()->index('created_at');
            $table->dateTime('updated_at')->nullable()->index('updated_at');
            $table->integer('is_deleted')->nullable()->default(0)->index('is_deleted');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('apps_plans_prices');
    }
};