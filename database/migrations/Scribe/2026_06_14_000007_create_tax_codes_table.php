<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::connection('accounting')->create('tax_codes', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('apps_id');
            $table->unsignedInteger('companies_id');
            $table->uuid('uuid');

            $table->string('code', 64);
            $table->string('name', 191);
            $table->string('jurisdiction', 32)->nullable();        // 'DO' | 'US-CA' | 'EU-DE' | ...

            $table->boolean('is_active')->default(true);

            $table->string('source', 32)->default('kanvas');
            $table->string('external_id', 191)->nullable();

            $table->json('metadata')->nullable();
            $table->boolean('is_deleted')->default(false);
            $table->unsignedInteger('users_id')->nullable();
            $table->timestamps();

            $table->unique(['apps_id', 'companies_id', 'code'], 'tax_codes_app_company_code_uq');
            $table->unique(['apps_id', 'source', 'external_id'], 'tax_codes_app_source_external_uq');
            $table->index(['apps_id', 'companies_id', 'is_active'], 'tax_codes_app_company_active_idx');
        });
    }

    public function down(): void
    {
        Schema::connection('accounting')->dropIfExists('tax_codes');
    }
};
