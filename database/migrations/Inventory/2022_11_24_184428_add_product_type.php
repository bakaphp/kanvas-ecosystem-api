<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('products_types', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('apps_id')->unsigned();
            $table->bigIntegeR('companies_id')->unsigned();
            $table->bigIntegeR('users_id')->unsigned();
            $table->string('name');
            $table->char('uuid', 37)->unique();
            $table->string('slug');
            $table->string('description')->nullable();
            $table->integer('weight');
            $table->boolean('is_deleted')->default(0);
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();
            $table->unique(['companies_id', 'slug']);
            $table->index('apps_id');
            $table->index('companies_id');
            $table->index('weight');
            $table->index('slug');
            $table->index('uuid');
            $table->index('is_deleted');
            $table->index('users_id');
            $table->index('created_at');
            $table->index('updated_at');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('products_types');
    }
};
