<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('companies_kanvas_modules', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->unsignedInteger('apps_id');
            $table->unsignedBigInteger('companies_id');
            $table->unsignedBigInteger('kanvas_modules_id');

            $table->boolean('is_active')->default(true);
            $table->boolean('is_deleted')->default(false);

            $table->timestamps();

            $table->unique(
                ['apps_id', 'companies_id', 'kanvas_modules_id'],
                'uniq_company_app_module',
            );
            $table->index(['companies_id', 'is_active'], 'idx_company_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('companies_kanvas_modules');
    }
};
