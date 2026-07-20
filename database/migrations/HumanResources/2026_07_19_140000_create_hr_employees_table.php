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
        Schema::connection('hr')->create('hr_employees', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->char('uuid', 36)->unique();
            $table->unsignedInteger('apps_id');
            $table->unsignedBigInteger('companies_id');
            $table->unsignedBigInteger('companies_branches_id')->nullable();
            $table->unsignedBigInteger('users_id');
            $table->unsignedBigInteger('people_id');
            $table->unsignedBigInteger('department_id')->nullable();
            $table->unsignedBigInteger('position_id');
            $table->unsignedBigInteger('manager_employee_id')->nullable();
            $table->string('reporting_path')->nullable();
            $table->string('employee_number', 64)->nullable();
            $table->text('description')->nullable();
            $table->string('employment_type', 32)->default('employee');
            $table->string('home_entity', 128)->nullable();
            $table->string('status', 32)->default('onboarding');
            $table->date('hired_at');
            $table->date('terminated_at')->nullable();
            $table->boolean('end_is_voluntary')->nullable();
            $table->boolean('is_deleted')->default(0);
            $table->timestamp('created_at')->default(DB::raw('CURRENT_TIMESTAMP'));
            $table->timestamp('updated_at')->nullable();

            $table->index(['apps_id', 'companies_id', 'is_deleted'], 'idx_emp_tenant');
            $table->index(['users_id'], 'idx_emp_user');
            $table->index(['people_id'], 'idx_emp_people');
            $table->index(['department_id'], 'idx_emp_department');
            $table->index(['position_id'], 'idx_emp_position');
            $table->index(['apps_id', 'companies_id', 'manager_employee_id'], 'idx_emp_manager');
            $table->index(['reporting_path'], 'idx_emp_reporting_path');
        });
    }

    public function down(): void
    {
        Schema::connection('hr')->dropIfExists('hr_employees');
    }
};
