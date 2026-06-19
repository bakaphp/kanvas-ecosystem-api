<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::connection('accounting')->create('fiscal_periods', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('apps_id');
            $table->unsignedInteger('companies_id');
            $table->uuid('uuid');

            $table->date('period_start');
            $table->date('period_end');

            $table->enum('status', ['open', 'soft_closed', 'hard_closed'])->default('open');

            $table->timestamp('closed_at')->nullable();
            $table->unsignedInteger('closed_by_users_id')->nullable();
            $table->text('close_notes')->nullable();

            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['apps_id', 'companies_id', 'period_start'], 'fiscal_periods_app_company_start_uq');
            $table->index(['apps_id', 'companies_id', 'status'], 'fiscal_periods_app_company_status_idx');
            $table->index(['apps_id', 'companies_id', 'period_start', 'period_end'], 'fiscal_periods_app_company_range_idx');
        });
    }

    public function down(): void
    {
        Schema::connection('accounting')->dropIfExists('fiscal_periods');
    }
};
