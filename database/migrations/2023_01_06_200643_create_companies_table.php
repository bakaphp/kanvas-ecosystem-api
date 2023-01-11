<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateCompaniesTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('companies', function (Blueprint $table) {
            $table->integer('id', true);
            $table->char('uuid', 36)->nullable()->index('uuid');
            $table->string('name', 100)->nullable();
            $table->string('profile_image', 45)->nullable();
            $table->text('website')->nullable();
            $table->text('address')->nullable();
            $table->string('zipcode', 50)->nullable();
            $table->string('stripe_id')->nullable()->index('stripe_id');
            $table->string('email', 50)->nullable()->index('email');
            $table->string('language', 3)->nullable()->index('language');
            $table->string('timezone', 50)->nullable()->index('timezone');
            $table->string('phone', 64)->nullable();
            $table->integer('users_id')->index('users_id');
            $table->boolean('has_activities')->default(false)->index('has_activities');
            $table->integer('currency_id')->nullable()->index('currency_id');
            $table->integer('system_modules_id')->nullable()->index('system_modules_id');
            $table->string('country_code', 64)->nullable()->index('country_code');
            $table->dateTime('created_at')->nullable()->index('created_at');
            $table->dateTime('updated_at')->nullable()->index('updated_at');
            $table->integer('is_deleted')->nullable()->index('is_deleted');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('companies');
    }
}
