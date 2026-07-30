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
        Schema::connection('hr')->create('hr_positions', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->char('uuid', 36)->unique();
            $table->unsignedInteger('apps_id');
            $table->unsignedBigInteger('companies_id');
            $table->unsignedBigInteger('users_id')->nullable();
            $table->unsignedBigInteger('department_id')->nullable();
            $table->string('title', 255);
            $table->string('level', 64)->nullable();
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(1);
            $table->boolean('is_deleted')->default(0);
            $table->timestamp('created_at')->default(DB::raw('CURRENT_TIMESTAMP'));
            $table->timestamp('updated_at')->nullable();

            $table->index(['apps_id', 'companies_id', 'is_deleted'], 'idx_position_tenant');
            $table->index(['department_id'], 'idx_position_dept');
        });
    }

    public function down(): void
    {
        Schema::connection('hr')->dropIfExists('hr_positions');
    }
};
