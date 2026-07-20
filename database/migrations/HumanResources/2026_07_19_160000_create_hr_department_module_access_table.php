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
        Schema::connection('hr')->create('hr_department_module_access', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedInteger('apps_id');
            $table->unsignedBigInteger('companies_id');
            $table->unsignedBigInteger('users_id')->nullable();
            $table->unsignedBigInteger('department_id');
            $table->string('module_slug', 128);
            $table->string('level', 16)->default('none');
            $table->boolean('is_deleted')->default(0);
            $table->timestamp('created_at')->default(DB::raw('CURRENT_TIMESTAMP'));
            $table->timestamp('updated_at')->nullable();

            $table->unique(['companies_id', 'department_id', 'module_slug', 'is_deleted'], 'uq_dma');
            $table->index(['department_id'], 'idx_dma_dept');
            $table->index(['apps_id', 'companies_id', 'is_deleted'], 'idx_dma_tenant');
        });
    }

    public function down(): void
    {
        Schema::connection('hr')->dropIfExists('hr_department_module_access');
    }
};
