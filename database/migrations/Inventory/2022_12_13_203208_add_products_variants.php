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
        Schema::create('products_variants', function (Blueprint $table) {
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_520_ci';
            $table->id();
            $table->bigInteger('apps_id')->unsigned();
            $table->bigInteger('companies_id')->unsigned();
            $table->bigInteger('users_id')->unsigned();
            $table->bigInteger('products_id')->unsigned();
            $table->char('uuid', 100)->unique();
            $table->text('name');
            $table->string('slug', 100);
            $table->text('description')->nullable();
            $table->text('short_description')->nullable();
            $table->text('html_description')->nullable();
            $table->string('sku', 64)->nullable();
            $table->string('ean', 64)->nullable();
            $table->string('barcode', 64)->nullable();
            $table->string('serial_number', 190)->nullable();
            $table->boolean('is_published')->default(1);
            $table->boolean('is_deleted')->default(0);
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();
            $table->foreign('products_id')->references('id')->on('products');
            $table->unique(['companies_id', 'slug']);
            $table->index('products_id');
            $table->index('uuid');
            $table->index('sku');
            $table->index('ean');
            $table->index('barcode');
            $table->index('serial_number');
            $table->index('apps_id');
            $table->index('companies_id');
            $table->index('users_id');
            $table->index('slug');
            $table->index('is_published');
            $table->index('is_deleted');
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
        Schema::dropIfExists('products_variants');
    }
};
