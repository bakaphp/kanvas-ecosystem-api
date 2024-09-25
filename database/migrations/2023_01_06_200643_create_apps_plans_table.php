<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateAppsPlansTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('apps_plans', function (Blueprint $table) {
            $table->integer('id', true);
            $table->integer('apps_id')->nullable()->index('apps_id');
            $table->string('name')->nullable();
            $table->string('payment_interval', 16)->nullable();
            $table->string('description')->nullable();
            $table->string('stripe_id', 100)->nullable()->index('stripe_id');
            $table->string('stripe_plan', 100)->nullable();
            $table->decimal('pricing', 10)->nullable();
            $table->decimal('pricing_annual', 10)->nullable();
            $table->integer('currency_id')->nullable()->index('currency_id');
            $table->integer('free_trial_dates')->nullable()->index('free_trial_dates');
            $table->integer('is_default')->nullable()->default(0)->index('is_default');
            $table->integer('payment_frequencies_id')->nullable()->default(1)->index('payment_frequencies_id')->comment('The integers in this field represent months');
            $table->date('created_at')->nullable()->index('created_at');
            $table->dateTime('updated_at')->nullable()->index('updated_at');
            $table->integer('is_deleted')->nullable()->default(0)->index('is_deleted');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('apps_plans');
    }
}
