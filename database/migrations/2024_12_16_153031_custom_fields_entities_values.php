<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('custom_fields_entities_values', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->bigInteger('apps_id')->unsigned()->index();
            $table->bigInteger('companies_id')->unsigned()->index();
            $table->bigInteger('users_id')->unsigned()->index();
            $table->bigInteger('custom_fields_id')->unsigned()->index();
            $table->bigInteger('custom_fields_modules_id')->unsigned()->index();
            $table->bigInteger('system_modules_id')->unsigned()->index();
            $table->bigInteger('entity_id')->unsigned()->index();
            $table->string('model_name');
            $table->string('value');
            $table->dateTime('created_at')->index('created_at');
            $table->dateTime('updated_at')->nullable()->index('updated_at');
            $table->integer('is_deleted')->default(0)->index('is_deleted');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
    }
};
