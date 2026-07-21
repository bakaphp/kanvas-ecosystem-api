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
        Schema::connection('hr')->create('hr_pay_bands', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->char('uuid', 36)->unique();
            $table->unsignedInteger('apps_id');
            $table->unsignedBigInteger('companies_id');
            $table->unsignedBigInteger('users_id')->nullable();
            $table->unsignedBigInteger('position_id')->nullable();
            $table->string('name', 255)->nullable();
            $table->string('level', 64)->nullable();
            $table->string('currency', 3)->default('USD');
            $table->string('pay_frequency', 24)->default('annual');
            $table->decimal('min_amount', 15, 2);
            $table->decimal('mid_amount', 15, 2)->nullable();
            $table->decimal('max_amount', 15, 2);
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->boolean('is_deleted')->default(0);
            $table->timestamp('created_at')->default(DB::raw('CURRENT_TIMESTAMP'));
            $table->timestamp('updated_at')->nullable();

            $table->index(['position_id'], 'idx_band_position');
            $table->index(['apps_id', 'companies_id', 'is_deleted'], 'idx_band_tenant');
        });

        Schema::connection('hr')->create('hr_employee_compensations', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->char('uuid', 36)->unique();
            $table->unsignedInteger('apps_id');
            $table->unsignedBigInteger('companies_id');
            $table->unsignedBigInteger('employee_id');
            $table->unsignedBigInteger('pay_band_id')->nullable();
            $table->decimal('amount', 15, 2);
            $table->string('currency', 3)->default('USD');
            $table->string('pay_frequency', 24)->default('annual');
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->string('change_reason', 255)->nullable();
            $table->unsignedBigInteger('recorded_by_users_id')->nullable();
            $table->boolean('is_deleted')->default(0);
            $table->timestamp('created_at')->default(DB::raw('CURRENT_TIMESTAMP'));
            $table->timestamp('updated_at')->nullable();

            $table->index(['employee_id', 'effective_from'], 'idx_comp_emp');
            $table->index(['apps_id', 'companies_id', 'is_deleted'], 'idx_comp_tenant');
        });
    }

    public function down(): void
    {
        Schema::connection('hr')->dropIfExists('hr_employee_compensations');
        Schema::connection('hr')->dropIfExists('hr_pay_bands');
    }
};
