<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateCustomFiltersConditionsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('custom_filters_conditions', function (Blueprint $table) {
            $table->integer('custom_filter_id')->index('custom_filter_id');
            $table->integer('position')->index('position');
            $table->string('conditional', 5);
            $table->char('field', 100)->default('');
            $table->string('value');
            $table->string('comparator', 10);
            $table->dateTime('created_at')->index('created_at');
            $table->dateTime('updated_at')->nullable()->index('updated_at');
            $table->integer('is_deleted')->default(0)->index('is_deleted');

            $table->primary(['custom_filter_id', 'field']);
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('custom_filters_conditions');
    }
}
