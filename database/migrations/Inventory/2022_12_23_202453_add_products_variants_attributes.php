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
        Schema::create('products_variants_attributes', function (Blueprint $table) {
            $table->primary(['products_variants_id', 'attributes_id'], 'products_variants_attributes_primary');
            $table->bigInteger('products_variants_id')->unsigned();
            $table->bigInteger('attributes_id')->unsigned();
            $table->text('value');
            $table->foreign('products_variants_id')->references('id')->on('products_variants');
            $table->foreign('attributes_id')->references('id')->on('attributes');
            $table->boolean('is_deleted')->default(0);
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();
            $table->index('created_at');
            $table->index('updated_at');
            $table->index('is_deleted');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('products_variants_attributes');
    }
};
