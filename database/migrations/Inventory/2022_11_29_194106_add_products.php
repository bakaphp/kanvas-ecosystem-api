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
        //
        Schema::create('products', function (Blueprint $table) {
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_520_ci';
            $table->id();
            $table->bigInteger('apps_id')->unsigned();
            $table->bigInteger('companies_id')->unsigned();
            $table->bigInteger('users_id')->unsigned();
            $table->bigInteger('products_types_id')->unsigned()->nullable();
            $table->char('uuid', 37)->unique();
            $table->text('name');
            $table->string('slug');
            $table->text('description');
            $table->text('short_description')->nullable();
            $table->text('html_description')->nullable();
            $table->text('warranty_terms')->nullable();
            $table->string('upc')->nullable();
            $table->boolean('is_published')->default(0);
            $table->timestamp('published_at')->nullable();
            $table->boolean('is_deleted')->default(0);
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();
            $table->index('apps_id');
            $table->index('companies_id');
            $table->index('products_types_id');
            $table->index('uuid');
            $table->index('slug');
            $table->index('is_published');
            $table->index('is_deleted');
            $table->index('users_id');
            $table->index('published_at');
            $table->index('created_at');
            $table->index('updated_at');
            $table->foreign('products_types_id')->references('id')->on('products_types');
            $table->unique(['companies_id', 'slug']);

        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        //
        Schema::dropIfExists('products');
    }
};
