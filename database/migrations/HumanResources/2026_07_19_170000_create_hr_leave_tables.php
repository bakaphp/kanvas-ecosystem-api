<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    protected $connection = 'hr';

    public function up(): void
    {
        Schema::connection('hr')->create('hr_leave_types', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->char('uuid', 36)->unique();
            $table->unsignedInteger('apps_id');
            $table->unsignedBigInteger('companies_id');
            $table->unsignedBigInteger('users_id')->nullable();
            $table->string('name', 255);
            $table->string('slug', 255);
            $table->boolean('is_paid')->default(1);
            $table->string('accrual_method', 32)->default('annual_allotment');
            $table->decimal('default_annual_days', 6, 2)->nullable();
            $table->decimal('carryover_max_days', 6, 2)->nullable();
            $table->boolean('requires_approval')->default(1);
            $table->string('color', 16)->nullable();
            $table->boolean('is_active')->default(1);
            $table->boolean('is_deleted')->default(0);
            $table->timestamp('created_at')->default(DB::raw('CURRENT_TIMESTAMP'));
            $table->timestamp('updated_at')->nullable();

            $table->unique(['companies_id', 'apps_id', 'slug', 'is_deleted'], 'uq_leave_type_slug');
            $table->index(['apps_id', 'companies_id', 'is_deleted'], 'idx_leave_type_tenant');
        });

        Schema::connection('hr')->create('hr_leave_balances', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedInteger('apps_id');
            $table->unsignedBigInteger('companies_id');
            $table->unsignedBigInteger('employee_id');
            $table->unsignedBigInteger('leave_type_id');
            $table->unsignedSmallInteger('period_year');
            $table->decimal('entitled_days', 6, 2)->default(0);
            $table->decimal('accrued_days', 6, 2)->default(0);
            $table->decimal('carried_over_days', 6, 2)->default(0);
            $table->decimal('used_days', 6, 2)->default(0);
            $table->decimal('pending_days', 6, 2)->default(0);
            $table->boolean('is_deleted')->default(0);
            $table->timestamp('created_at')->default(DB::raw('CURRENT_TIMESTAMP'));
            $table->timestamp('updated_at')->nullable();

            $table->unique(['companies_id', 'employee_id', 'leave_type_id', 'period_year', 'is_deleted'], 'uq_balance');
            $table->index(['employee_id'], 'idx_balance_emp');
            $table->index(['apps_id', 'companies_id', 'is_deleted'], 'idx_balance_tenant');
        });

        Schema::connection('hr')->create('hr_leave_requests', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->char('uuid', 36)->unique();
            $table->unsignedInteger('apps_id');
            $table->unsignedBigInteger('companies_id');
            $table->unsignedBigInteger('employee_id');
            $table->unsignedBigInteger('leave_type_id');
            $table->date('start_date');
            $table->date('end_date');
            $table->decimal('days', 6, 2);
            $table->string('reason', 500)->nullable();
            $table->string('status', 24)->default('pending');
            $table->unsignedBigInteger('approver_employee_id')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->boolean('is_deleted')->default(0);
            $table->timestamp('created_at')->default(DB::raw('CURRENT_TIMESTAMP'));
            $table->timestamp('updated_at')->nullable();

            $table->index(['employee_id', 'status'], 'idx_lr_emp');
            $table->index(['leave_type_id'], 'idx_lr_type');
            $table->index(['start_date', 'end_date'], 'idx_lr_dates');
            $table->index(['apps_id', 'companies_id', 'is_deleted'], 'idx_lr_tenant');
        });
    }

    public function down(): void
    {
        Schema::connection('hr')->dropIfExists('hr_leave_requests');
        Schema::connection('hr')->dropIfExists('hr_leave_balances');
        Schema::connection('hr')->dropIfExists('hr_leave_types');
    }
};
