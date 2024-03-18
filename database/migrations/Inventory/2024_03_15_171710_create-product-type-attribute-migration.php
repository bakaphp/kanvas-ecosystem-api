<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('products_types_attributes', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('products_types_id')->unsigned();
            $table->bigInteger('attributes_id')->unsigned();
            $table->boolean('to_variants')->default(false);
            $table->boolean('is_deleted')->default(0);
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();
            $table->foreign('products_types_id')->references('id')->on('products_types');
            $table->foreign('attributes_id')->references('id')->on('attributes');
            $table->index(['products_types_id', 'attributes_id'], 'products_types_attributes_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        //
    }
};
