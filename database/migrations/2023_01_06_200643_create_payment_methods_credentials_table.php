<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreatePaymentMethodsCredentialsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('payment_methods_credentials', function (Blueprint $table) {
            $table->integer('id', true);
            $table->integer('users_id')->index('users_id');
            $table->integer('companies_groups_id')->index('companies_id');
            $table->integer('apps_id')->index('apps_id');
            $table->string('stripe_card_id')->nullable()->index('stripe_card_id');
            $table->integer('payment_methods_id')->index('payment_methods_id');
            $table->string('payment_ending_numbers', 8)->index('payment_ending_numbers');
            $table->string('payment_methods_brand', 32)->nullable();
            $table->string('expiration_date', 50)->default('')->index('expiration_date');
            $table->string('zip_code', 12)->nullable();
            $table->boolean('is_default')->nullable()->default(false)->index('is_default');
            $table->dateTime('created_at')->nullable()->index('created_at');
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
        Schema::dropIfExists('payment_methods_credentials');
    }
}
