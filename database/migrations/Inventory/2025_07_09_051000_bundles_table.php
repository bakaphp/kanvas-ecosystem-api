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
        Schema::create('bundles', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('apps_id');
            $table->unsignedBigInteger('companies_id');
            $table->unsignedBigInteger('users_id');
            $table->unsignedBigInteger('variant_id')->nullable();
            $table->string('slug');
            $table->string('name');
            $table->string('description')->nullable();
            $table->integer('weight')->default(0);
            $table->string('execution_mode')->default('manual');
            $table->tinyInteger('expose_as_product')->default(false);
            $table->json('config')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();
            $table->boolean('is_deleted')->default(0);

            // Indexes
            $table->index('apps_id');
            $table->index('companies_id');
            $table->index('users_id');
            $table->index('variant_id');
            $table->index('slug');
            $table->index('weight');
            $table->index('created_at');
            $table->index('updated_at');
            $table->index('is_deleted');
            
            // Composite indexes
            $table->index(['apps_id', 'companies_id']);
            $table->index(['companies_id', 'is_deleted']);
            $table->index(['apps_id', 'is_deleted']);
            $table->index(['apps_id', 'companies_id', 'is_deleted']);
            $table->index(['users_id', 'is_deleted']);
            $table->index(['variant_id', 'is_deleted']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('bundles');
    }
};
