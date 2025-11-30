<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('category_resource', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('system_modules_id');
            $table->unsignedBigInteger('categories_id')->nullable();
            $table->boolean('is_deleted')->default(0);
            $table->timestamps();

            $table->index(['system_modules_id', 'categories_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('category_resource');
    }
};
