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
        Schema::create('bundle_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('variant_id')->nullable();
            $table->unsignedBigInteger('bundle_id');
            $table->integer('weight')->default(0);
            $table->float('quantity')->default(1);
            $table->string('unit')->default('unit');
            $table->json('config')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();
            $table->boolean('is_deleted')->default(0);

            // Indexes
            $table->index('bundle_id');
            $table->index('variant_id');
            $table->index('weight');
            $table->index('created_at');
            $table->index('updated_at');
            $table->index('is_deleted');
            
            // Composite indexes
            $table->index(['bundle_id', 'is_deleted']);
            $table->index(['variant_id', 'is_deleted']);
            $table->index(['bundle_id', 'variant_id']);
            $table->index(['bundle_id', 'variant_id', 'is_deleted']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('bundle_items');
    }
};
