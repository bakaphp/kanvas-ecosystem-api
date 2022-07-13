<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateCountriesCitiesTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('countries_cities', function (Blueprint $table) {
            $table->integer('id', true);
            $table->integer('states_id')->nullable()->index('states_id');
            $table->integer('countries_id')->nullable()->index('countries_id');
            $table->string('name');
            $table->decimal('latitude', 10, 0)->nullable()->index('latitude');
            $table->decimal('longitude', 10, 0)->nullable()->index('longitude');
            $table->dateTime('created_at')->useCurrent()->index('created_at');
            $table->dateTime('updated_at')->nullable()->index('updated_at');
            $table->integer('is_deleted')->default(0)->index('is_deleted');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('countries_cities');
    }
}
