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
        Schema::connection('hr')->create('hr_seat_assignments', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedInteger('apps_id');
            $table->unsignedBigInteger('companies_id');
            $table->unsignedBigInteger('users_id')->nullable();
            $table->unsignedBigInteger('employee_id');
            $table->unsignedBigInteger('department_id');
            $table->unsignedSmallInteger('allocation_pct')->default(100);
            $table->boolean('is_primary')->default(1);
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->boolean('is_deleted')->default(0);
            $table->timestamp('created_at')->default(DB::raw('CURRENT_TIMESTAMP'));
            $table->timestamp('updated_at')->nullable();

            $table->index(['employee_id', 'is_deleted'], 'idx_seat_emp');
            $table->index(['department_id', 'is_deleted'], 'idx_seat_dept');
            $table->index(['apps_id', 'companies_id', 'is_deleted'], 'idx_seat_tenant');
        });
    }

    public function down(): void
    {
        Schema::connection('hr')->dropIfExists('hr_seat_assignments');
    }
};
