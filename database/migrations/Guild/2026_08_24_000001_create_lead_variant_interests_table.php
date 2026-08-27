<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::connection('crm')->create('lead_variant_interests', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('apps_id');
            $table->unsignedInteger('companies_id');
            $table->unsignedInteger('users_id');
            $table->unsignedBigInteger('leads_id');
            $table->unsignedBigInteger('variants_id');
            $table->string('interest_type', 32)->default('primary');
            $table->decimal('price_at_interest', 12, 2)->nullable();
            $table->boolean('is_active')->default(true);
            $table->json('metadata')->nullable();
            $table->boolean('is_deleted')->default(false);
            $table->timestamps();

            $table->index(
                ['apps_id', 'companies_id', 'leads_id'],
                'lvi_app_company_lead_idx'
            );
            $table->index(
                ['apps_id', 'companies_id', 'variants_id'],
                'lvi_app_company_variant_idx'
            );
            $table->index(
                ['leads_id', 'is_active', 'is_deleted'],
                'lvi_lead_active_deleted_idx'
            );
            $table->unique(
                ['apps_id', 'companies_id', 'leads_id', 'variants_id', 'interest_type'],
                'lvi_tenant_lead_variant_type_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::connection('crm')->dropIfExists('lead_variant_interests');
    }
};
