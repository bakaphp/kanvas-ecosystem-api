<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration {
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('products_attributes', function (Blueprint $table) {
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_520_ci';
            $table->primary(['products_id', 'attributes_id']);
            $table->bigInteger('products_id')->unsigned();
            $table->bigInteger('attributes_id')->unsigned();
            $table->text('value');
            $table->boolean('is_deleted')->default(0);
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();
            $table->index('products_id');
            $table->index('attributes_id');
            $table->index('is_deleted');
            $table->index('created_at');
            $table->index('updated_at');
            $table->foreign('products_id')->references('id')->on('products');
            $table->foreign('attributes_id')->references('id')->on('attributes');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('products_attributes');
    }
};
