<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Subaccounts are the second GL coding dimension (alongside the account) — the org segment a line
 * belongs to (department / cost-center / location / project). Imported from ERPs that use them
 * (Acumatica `Sub`); a pure reference list the coding agent + JE lines point at.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::connection('accounting')->create('subaccounts', function (Blueprint $table): void {
            $table->id();
            $table->unsignedInteger('apps_id');
            $table->unsignedInteger('companies_id');
            $table->uuid('uuid');

            $table->string('sub_code', 64);
            $table->string('description', 191)->nullable();

            $table->boolean('is_active')->default(true);

            $table->string('source', 32)->default('kanvas');
            $table->string('external_id', 191)->nullable();
            $table->timestamp('last_synced_at')->nullable();

            $table->json('metadata')->nullable();

            $table->boolean('is_deleted')->default(false);
            $table->unsignedInteger('users_id')->nullable();
            $table->timestamps();

            $table->unique(['apps_id', 'companies_id', 'sub_code'], 'subaccounts_app_company_code_uq');
            $table->unique(['apps_id', 'source', 'external_id'], 'subaccounts_app_source_external_uq');
            $table->index(['apps_id', 'companies_id', 'is_active'], 'subaccounts_app_company_active_idx');
            $table->index(['uuid'], 'subaccounts_uuid_idx');
        });
    }

    public function down(): void
    {
        Schema::connection('accounting')->dropIfExists('subaccounts');
    }
};
