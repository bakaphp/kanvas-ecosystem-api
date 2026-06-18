<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::connection('accounting')->create('accounts', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('apps_id');
            $table->unsignedInteger('companies_id');
            $table->uuid('uuid');

            $table->string('account_number', 32);
            $table->string('name', 191);
            $table->text('description')->nullable();

            $table->enum('account_type', [
                'asset',
                'liability',
                'equity',
                'revenue',
                'expense',
                'cogs',
                'other_income',
                'other_expense',
            ]);
            $table->string('account_sub_type', 64)->nullable();

            $table->unsignedBigInteger('parent_account_id')->nullable();

            $table->string('currency', 3)->nullable();

            $table->boolean('is_active')->default(true);
            $table->boolean('is_system')->default(false);

            $table->string('source', 32)->default('kanvas');
            $table->string('external_id', 191)->nullable();
            $table->timestamp('last_synced_at')->nullable();

            $table->json('metadata')->nullable();

            $table->boolean('is_deleted')->default(false);
            $table->unsignedInteger('users_id')->nullable();
            $table->timestamps();

            $table->unique(['apps_id', 'companies_id', 'account_number'], 'accounts_app_company_number_uq');
            $table->unique(['apps_id', 'source', 'external_id'], 'accounts_app_source_external_uq');

            $table->index(['apps_id', 'companies_id', 'account_type', 'is_active'], 'accounts_app_company_type_active_idx');
            $table->index(['parent_account_id'], 'accounts_parent_idx');
            $table->index(['uuid'], 'accounts_uuid_idx');
        });
    }

    public function down(): void
    {
        Schema::connection('accounting')->dropIfExists('accounts');
    }
};
