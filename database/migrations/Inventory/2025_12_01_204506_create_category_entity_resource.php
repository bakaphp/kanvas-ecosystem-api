<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('category_resource_entity', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('categories_id');
            $table->unsignedBigInteger('resource_id');
            $table->string('resource_type');
            $table->timestamps();
            $table->boolean('is_deleted')->default(0);

            $table->foreign('categories_id')
                ->references('id')
                ->on('categories')
                ->onDelete('cascade');

            $table->index(['resource_id', 'resource_type']);
            $table->index('categories_id');

            $table->unique(['categories_id', 'resource_id', 'resource_type'], 'category_resource_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('category_resource_entity');
    }
};
