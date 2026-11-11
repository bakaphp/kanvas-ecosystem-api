<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        if (Schema::connection('commerce')->hasTable('tax_categories')) {
            return;
        }

        Schema::connection('commerce')->create('tax_categories', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->char('uuid', 36)->unique();
            $table->unsignedBigInteger('apps_id');
            $table->string('code', 32);
            $table->string('name', 128);
            $table->string('description', 255)->nullable();
            $table->timestamps();
            $table->tinyInteger('is_deleted')->default(0);

            $table->index(['apps_id', 'code'], 'tax_categories_app_code_idx');
        });
    }

    public function down(): void
    {
        Schema::connection('commerce')->dropIfExists('tax_categories');
    }
};
