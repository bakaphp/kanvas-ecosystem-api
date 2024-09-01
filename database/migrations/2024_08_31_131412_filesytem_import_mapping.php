<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::dropIfExists('importers_templates');
        Schema::dropIfExists('attributes_importers_templates');
        Schema::dropIfExists('attributes_mappers_importers_templates');
        Schema::dropIfExists('mappers_importers_templates');
        Schema::dropIfExists('importers_requests');

        Schema::create('filesystem_mappers', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->index();
            $table->bigInteger('apps_id')->index();
            $table->bigInteger('users_id')->index();
            $table->bigInteger('companies_id')->index();
            $table->bigInteger('companies_branches_id')->index();
            $table->bigInteger('system_modules_id')->index();
            $table->string('name');
            $table->json('file_header');
            $table->json('mapping');
            $table->json('configuration')->nullable();
            $table->dateTime('created_at')->index();
            $table->dateTime('updated_at')->nullable()->index();
            $table->tinyInteger('is_deleted')->index();

            $table->index(['apps_id', 'companies_id', 'companies_branches_id', 'system_modules_id'],'filesystem_mapping_index');
            $table->index(['apps_id', 'companies_id', 'system_modules_id']);
            $table->index(['apps_id', 'uuid']);
            $table->index(['apps_id', 'uuid', 'system_modules_id']);
        });

        Schema::create('filesystem_imports', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->index();
            $table->bigInteger('apps_id')->index();
            $table->bigInteger('users_id')->index();
            $table->bigInteger('regions_id')->index();
            $table->bigInteger('companies_id')->index();
            $table->bigInteger('companies_branches_id')->index();
            $table->bigInteger('filesystem_id')->index();
            $table->bigInteger('filesystem_mapper_id', false, true)->index();
            $table->foreign('filesystem_mapper_id')->references('id')->on('filesystem_mappers');
            $table->longText('results')->nullable();
            $table->longText('exception')->nullable();
            $table->enum('status', ['pending', 'processing', 'finished', 'failed'])->default('pending');
            $table->dateTime('created_at')->index();
            $table->dateTime('updated_at')->nullable()->index();
            $table->bigInteger('finished_at')->nullable()->index();
            $table->tinyInteger('is_deleted')->index();

            $table->index(['apps_id', 'companies_id', 'companies_branches_id', 'filesystem_id'], 'filesystem_imports_index');
            $table->index(['apps_id', 'companies_id', 'filesystem_id']);
            $table->index(['apps_id', 'uuid']);
            $table->index(['apps_id', 'uuid', 'filesystem_id']);
            $table->index(['apps_id', 'filesystem_mapper_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
    }
};
