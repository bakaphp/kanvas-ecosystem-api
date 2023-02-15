<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreatePeoplesAddressTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::connection('crm')->create('peoples_address', function (Blueprint $table) {
            $table->bigInteger('id', true);
            $table->bigInteger('peoples_id')->index('peoples_id');
            $table->string('address')->nullable();
            $table->string('address_2')->nullable();
            $table->string('city')->nullable();
            $table->string('state')->nullable();
            $table->char('zip', 50)->nullable();
            $table->tinyInteger('is_default')->default(1)->index('is_default');
            $table->integer('countries_id')->nullable()->index('country_id');
            $table->dateTime('created_at')->index('created_at');
            $table->dateTime('updated_at')->nullable()->index('updated_at');
            $table->boolean('is_deleted')->default(false)->index('is_deleted');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::connection('crm')->dropIfExists('peoples_address');
    }
}
