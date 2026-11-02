<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    protected $connection = 'commerce';

    public function up(): void
    {
        Schema::connection('commerce')->create('insurers', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedInteger('apps_id');
            $table->unsignedBigInteger('companies_id');
            $table->string('name', 191);
            $table->string('slug', 191);
            $table->string('provider', 64)->nullable();
            $table->string('logo', 500)->nullable();
            $table->string('status', 32)->default('active');
            $table->boolean('is_deleted')->default(0);
            $table->timestamp('created_at')->default(DB::raw('CURRENT_TIMESTAMP'));
            $table->timestamp('updated_at')->nullable();

            $table->unique(['apps_id', 'companies_id', 'slug'], 'insurers_tenant_slug_unique');
            $table->index(['apps_id', 'companies_id', 'status', 'is_deleted'], 'insurers_tenant_status_idx');
        });

        Schema::connection('commerce')->create('insurance_plans', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('insurers_id');
            $table->string('name', 191);
            $table->string('slug', 191);
            $table->string('type', 16);
            $table->string('external_code', 64)->nullable();
            $table->decimal('base_price', 12, 2)->nullable();
            $table->unsignedSmallInteger('validity_days')->nullable();
            $table->decimal('deductible', 12, 2)->nullable();
            $table->string('status', 32)->default('active');
            $table->boolean('sponsored')->default(0);
            $table->decimal('commission_rate', 5, 2)->default(0);
            $table->unsignedSmallInteger('recommended_score')->default(0);
            $table->boolean('is_deleted')->default(0);
            $table->timestamp('created_at')->default(DB::raw('CURRENT_TIMESTAMP'));
            $table->timestamp('updated_at')->nullable();

            $table->unique(['insurers_id', 'slug'], 'insurance_plans_insurer_slug_unique');
            $table->index(['type', 'status', 'is_deleted'], 'insurance_plans_type_status_idx');
            $table->foreign('insurers_id', 'insurance_plans_insurer_fk')->references('id')->on('insurers');
        });

        Schema::connection('commerce')->create('insurance_coverages', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('insurance_plans_id');
            $table->string('name', 191);
            $table->text('description')->nullable();
            $table->decimal('limit_amount', 14, 2)->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_deleted')->default(0);
            $table->timestamp('created_at')->default(DB::raw('CURRENT_TIMESTAMP'));
            $table->timestamp('updated_at')->nullable();

            $table->index(['insurance_plans_id', 'is_deleted', 'sort_order'], 'insurance_coverages_plan_idx');
            $table->foreign('insurance_plans_id', 'insurance_coverages_plan_fk')->references('id')->on('insurance_plans');
        });

        Schema::connection('commerce')->create('insurance_plan_exclusions', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('insurance_plans_id');
            $table->text('description');
            $table->boolean('is_deleted')->default(0);
            $table->timestamp('created_at')->default(DB::raw('CURRENT_TIMESTAMP'));
            $table->timestamp('updated_at')->nullable();

            $table->index(['insurance_plans_id', 'is_deleted'], 'insurance_exclusions_plan_idx');
            $table->foreign('insurance_plans_id', 'insurance_exclusions_plan_fk')->references('id')->on('insurance_plans');
        });

        Schema::connection('commerce')->create('insurance_plan_requirements', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('insurance_plans_id');
            $table->string('name', 191);
            $table->text('condition')->nullable();
            $table->boolean('is_deleted')->default(0);
            $table->timestamp('created_at')->default(DB::raw('CURRENT_TIMESTAMP'));
            $table->timestamp('updated_at')->nullable();

            $table->index(['insurance_plans_id', 'is_deleted'], 'insurance_requirements_plan_idx');
            $table->foreign('insurance_plans_id', 'insurance_requirements_plan_fk')->references('id')->on('insurance_plans');
        });
    }

    public function down(): void
    {
        Schema::connection('commerce')->dropIfExists('insurance_plan_requirements');
        Schema::connection('commerce')->dropIfExists('insurance_plan_exclusions');
        Schema::connection('commerce')->dropIfExists('insurance_coverages');
        Schema::connection('commerce')->dropIfExists('insurance_plans');
        Schema::connection('commerce')->dropIfExists('insurers');
    }
};
