<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('warehouses', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('companies_id')->unsigned();
            $table->bigInteger('apps_id')->unsigned();
            $table->bigInteger('users_id')->unsigned();
            $table->bigInteger('regions_id')->unsigned();
            $table->char('uuid', 37)->unique();
            $table->string('name');
            $table->string('location')->nullable();
            $table->boolean('is_default')->nullable()->default(false);
            $table->integer('is_published')->default(1);
            $table->integer('is_deleted')->default(0);
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();
            $table->index('companies_id');
            $table->index('apps_id');
            $table->index('regions_id');
            $table->index('is_default');
            $table->index('is_published');
            $table->index('is_deleted');
            $table->index('users_id');
            $table->index('created_at');
            $table->index('updated_at');
            $table->foreign('regions_id')->references('id')->on('regions');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('warehouses');
    }
};
