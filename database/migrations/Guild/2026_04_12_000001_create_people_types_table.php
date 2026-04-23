<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::connection('crm')->create('people_types', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->char('uuid', 36)->index('uuid');
            $table->bigInteger('apps_id')->index('apps_id');
            $table->bigInteger('companies_id')->index('companies_id');
            $table->bigInteger('users_id')->index('users_id');
            $table->string('name', 64);
            $table->string('slug', 64)->nullable();
            $table->text('description')->nullable();
            $table->json('config')->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('is_default')->default(false);
            $table->boolean('is_deleted')->default(false)->index('is_deleted');
            $table->dateTime('created_at')->index('created_at');
            $table->dateTime('updated_at')->nullable();

            $table->index(['apps_id', 'companies_id', 'is_deleted'], 'apps_companies_deleted');
            $table->unique(['slug', 'apps_id', 'companies_id'], 'unique_slug_app_company');
        });
    }

    public function down(): void
    {
        Schema::connection('crm')->dropIfExists('people_types');
    }
};
