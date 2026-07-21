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
        Schema::connection('hr')->create('hr_departments', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->char('uuid', 36)->unique();
            $table->unsignedInteger('apps_id');
            $table->unsignedBigInteger('companies_id');
            $table->unsignedBigInteger('companies_branches_id')->nullable();
            $table->unsignedBigInteger('users_id')->nullable();
            $table->unsignedBigInteger('parent_id')->nullable();
            $table->string('path')->nullable();
            $table->unsignedBigInteger('manager_employee_id')->nullable();
            $table->string('name', 255);
            $table->string('slug', 255);
            $table->string('code', 64)->nullable();
            $table->string('outcome_line', 255)->nullable();
            $table->text('description')->nullable();
            $table->boolean('is_deleted')->default(0);
            $table->timestamp('created_at')->default(DB::raw('CURRENT_TIMESTAMP'));
            $table->timestamp('updated_at')->nullable();

            $table->unique(['companies_id', 'apps_id', 'slug', 'is_deleted'], 'uq_dept_slug');
            $table->index(['apps_id', 'companies_id', 'is_deleted'], 'idx_dept_tenant');
            $table->index(['apps_id', 'companies_id', 'parent_id'], 'idx_dept_parent');
            $table->index(['path'], 'idx_dept_path');
        });
    }

    public function down(): void
    {
        Schema::connection('hr')->dropIfExists('hr_departments');
    }
};
