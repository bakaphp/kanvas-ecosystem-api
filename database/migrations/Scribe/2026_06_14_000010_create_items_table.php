<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::connection('accounting')->create('items', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('apps_id');
            $table->unsignedInteger('companies_id');
            $table->uuid('uuid');

            $table->string('item_number', 64);
            $table->string('name', 191);
            $table->text('description')->nullable();

            $table->enum('type', ['service', 'product', 'bundle', 'charge'])->default('service');

            // Cross-DB link to inventory.variants (no DB-level FK — Inventory is in a different DB)
            $table->unsignedBigInteger('inventory_variant_id')->nullable();

            $table->unsignedBigInteger('default_income_account_id')->nullable();
            $table->unsignedBigInteger('default_expense_account_id')->nullable();
            $table->unsignedBigInteger('default_tax_code_id')->nullable();

            $table->decimal('default_price_native', 18, 4)->nullable();
            $table->string('currency', 3)->nullable();

            $table->boolean('is_active')->default(true);

            $table->string('source', 32)->default('kanvas');
            $table->string('external_id', 191)->nullable();
            $table->timestamp('last_synced_at')->nullable();

            $table->json('metadata')->nullable();
            $table->boolean('is_deleted')->default(false);
            $table->unsignedInteger('users_id')->nullable();
            $table->timestamps();

            $table->unique(['apps_id', 'companies_id', 'item_number'], 'items_app_company_number_uq');
            $table->unique(['apps_id', 'source', 'external_id'], 'items_app_source_external_uq');
            // One accounting.item per variant — don't double-map a SKU.
            $table->unique(['apps_id', 'inventory_variant_id'], 'items_app_variant_uq');

            $table->index(['apps_id', 'companies_id', 'is_active'], 'items_app_company_active_idx');
            $table->index(['apps_id', 'companies_id', 'inventory_variant_id'], 'items_app_company_variant_idx');
        });
    }

    public function down(): void
    {
        Schema::connection('accounting')->dropIfExists('items');
    }
};
