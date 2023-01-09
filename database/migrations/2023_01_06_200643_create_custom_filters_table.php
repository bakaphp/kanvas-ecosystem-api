<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateCustomFiltersTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('custom_filters', function (Blueprint $table) {
            $table->integer('id', true);
            $table->integer('system_modules_id')->index('system_modules_id');
            $table->integer('apps_id')->index('apps_id');
            $table->integer('companies_id')->index('companies_id');
            $table->integer('companies_branch_id')->index('companies_branch_id');
            $table->integer('users_id')->index('users_id');
            $table->string('name', 100);
            $table->text('sequence_logic');
            $table->integer('total_conditions')->index('total_conditions');
            $table->text('description')->nullable();
            $table->dateTime('created_at')->index('created_at');
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
        Schema::dropIfExists('custom_filters');
    }
}
