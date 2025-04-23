<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('discount_types', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('apps_id');
            $table->string('name');
            $table->text('description')->nullable();
            $table->timestamps();
            $table->boolean('is_deleted')->default(false);

            $table->index('apps_id');
            $table->index('name');
            $table->index('is_deleted');
        });

        Schema::create('discounts', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('apps_id');
            $table->bigInteger('companies_id');
            $table->uuid('uuid');
            $table->string('name');
            $table->text('description')->nullable();
            $table->foreignId('discount_type_id')->constrained();
            $table->decimal('value', 10, 2);
            $table->boolean('is_percentage')->default(false);
            $table->decimal('min_order_value', 10, 2)->nullable();
            $table->decimal('max_discount_amount', 10, 2)->nullable();
            $table->string('code')->nullable()->unique();
            $table->timestamp('start_date')->nullable();
            $table->timestamp('end_date')->nullable();
            $table->boolean('is_active')->default(true);
            $table->integer('usage_limit')->nullable();
            $table->integer('usage_count')->default(0);
            $table->boolean('is_one_per_customer')->default(false);
            $table->timestamps();
            $table->boolean('is_deleted')->default(false);

            $table->unique(['apps_id', 'uuid']);
            $table->index('apps_id');
            $table->index('companies_id');
            $table->index('uuid');
            $table->index('name');
            $table->index('discount_type_id');
            $table->index('start_date');
            $table->index('end_date');
            $table->index('is_active');
            $table->index('is_deleted');
        });

        Schema::create('discount_conditions', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('apps_id');
            $table->foreignId('discount_id')->constrained()->onDelete('cascade');
            $table->enum('type', ['product', 'category', 'variant', 'customer', 'customer_group']);
            $table->enum('operator', ['in', 'not_in'])->default('in');
            $table->timestamps();
            $table->boolean('is_deleted')->default(false);

            $table->index('apps_id');
            $table->index('discount_id');
            $table->index('type');
            $table->index('operator');
            $table->index('is_deleted');
        });

        Schema::create('discount_condition_values', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('apps_id');
            $table->foreignId('condition_id')->constrained('discount_conditions')->onDelete('cascade');
            $table->string('value');
            $table->timestamps();
            $table->boolean('is_deleted')->default(false);

            $table->index('apps_id');
            $table->index('condition_id');
            $table->index('value');
            $table->index('is_deleted');
        });

        Schema::create('order_discounts', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('apps_id');
            $table->foreignId('order_id')->constrained()->onDelete('cascade');
            $table->foreignId('discount_id')->constrained();
            $table->decimal('amount', 10, 2);
            $table->timestamps();
            $table->boolean('is_deleted')->default(false);

            $table->index('apps_id');
            $table->index('order_id');
            $table->index('discount_id');
            $table->index('is_deleted');
        });

        Schema::create('order_item_discounts', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('apps_id');
            $table->foreignId('order_item_id')->constrained()->onDelete('cascade');
            $table->foreignId('discount_id')->constrained();
            $table->decimal('amount', 10, 2);
            $table->timestamps();
            $table->boolean('is_deleted')->default(false);

            $table->index('apps_id');
            $table->index('order_item_id');
            $table->index('discount_id');
            $table->index('is_deleted');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('discount_types');
        Schema::dropIfExists('discounts');
        Schema::dropIfExists('discount_conditions');
        Schema::dropIfExists('discount_condition_values');
        Schema::dropIfExists('order_discounts');
        Schema::dropIfExists('order_item_discounts');
    }
};
