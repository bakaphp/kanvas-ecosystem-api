<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up()
    {
        // Create actions table
        Schema::create('actions', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->char('uuid', 36)->unique();
            $table->unsignedInteger('apps_id');
            $table->unsignedBigInteger('companies_id');
            $table->unsignedBigInteger('users_id');
            $table->unsignedBigInteger('pipelines_id');
            $table->string('name', 150);
            $table->char('slug', 150)->default('')->unique();
            $table->longText('description')->nullable();
            $table->longText('icon')->nullable();
            $table->longText('form_fields')->nullable();
            $table->longText('form_config')->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('collects_info')->default(false);
            $table->boolean('is_published')->default(true);
            $table->dateTime('created_at');
            $table->dateTime('updated_at')->nullable();
            $table->boolean('is_deleted')->default(false);
            $table->index(['apps_id', 'companies_id']);
            $table->index(['uuid', 'apps_id', 'companies_id', 'is_deleted']);
            $table->index('pipelines_id');
        });

        // Create actions_custom_forms table
        Schema::create('actions_custom_forms', function (Blueprint $table) {
            $table->increments('id');
            $table->string('lead_uuid', 64)->default('');
            $table->unsignedInteger('companies_actions_id');
            $table->longText('form_structure');
            $table->dateTime('created_at')->nullable();
            $table->date('updated_at')->nullable();
            $table->boolean('is_deleted')->default(false);
            $table->index('lead_uuid');
        });

        // Create business_verticals table
        Schema::create('business_verticals', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedBigInteger('users_id');
            $table->string('name', 150);
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->dateTime('created_at');
            $table->dateTime('updated_at')->nullable();
            $table->boolean('is_deleted')->default(false);
            $table->index('users_id');
        });

        // Create business_verticals_actions table
        Schema::create('business_verticals_actions', function (Blueprint $table) {
            $table->unsignedInteger('business_verticals_id');
            $table->unsignedInteger('actions_id');
            $table->dateTime('created_at');
            $table->primary(['business_verticals_id', 'actions_id']);
        });

        // Create companies_actions table
        Schema::create('companies_actions', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->char('uuid', 36)->unique();
            $table->unsignedBigInteger('actions_id');
            $table->unsignedBigInteger('apps_id');
            $table->unsignedBigInteger('companies_id');
            $table->unsignedBigInteger('companies_branches_id');
            $table->unsignedBigInteger('users_id');
            $table->unsignedBigInteger('pipelines_id');
            $table->string('name', 150);
            $table->longText('description')->nullable();
            $table->longText('form_config')->nullable();
            $table->longText('icon')->nullable();
            $table->boolean('is_active')->default(false);
            $table->boolean('is_published')->default(false);
            $table->decimal('weight', 10, 2)->default(0.00);
            $table->dateTime('created_at');
            $table->dateTime('updated_at')->nullable();
            $table->boolean('is_deleted')->default(false);
            $table->index(['apps_id', 'companies_id']);
            $table->index(['apps_id', 'companies_id', 'companies_branches_id'], 'apps_companies_branch_index');
            $table->index('pipelines_id');
        });

        // Create companies_actions_visitors table
        Schema::create('companies_actions_visitors', function (Blueprint $table) {
            $table->increments('id');
            $table->char('visitors_id', 36);
            $table->char('leads_id', 36);
            $table->char('receivers_id', 36);
            $table->char('contacts_id', 36);
            $table->unsignedBigInteger('companies_id');
            $table->unsignedBigInteger('users_id');
            $table->unsignedInteger('companies_actions_id');
            $table->char('actions_slug', 50);
            $table->longText('request')->nullable();
            $table->dateTime('created_at');
            $table->dateTime('updated_at')->nullable();
            $table->boolean('is_deleted')->default(false);
            $table->index('leads_id');
            $table->index('receivers_id');
            $table->index('contacts_id');
            $table->index('companies_id');
            $table->index('actions_slug');
            $table->index('users_id');
            $table->index('visitors_id');
        });

        // Create companies_marketplace_apps table
        Schema::create('companies_marketplace_apps', function (Blueprint $table) {
            $table->unsignedInteger('marketplace_apps_id');
            $table->unsignedBigInteger('companies_id');
            $table->unsignedInteger('apps_id');
            $table->unsignedBigInteger('users_id');
            $table->longText('internal_configuration')->nullable();
            $table->longText('configuration');
            $table->boolean('is_active')->default(false);
            $table->dateTime('created_at');
            $table->dateTime('updated_at')->nullable();
            $table->boolean('is_deleted')->default(false);
            $table->primary(['marketplace_apps_id', 'companies_id']);
            $table->index('apps_id');
            $table->index('users_id');
        });

        // Create engagements table
        Schema::create('engagements', function (Blueprint $table) {
            $table->increments('id');
            $table->char('uuid', 36)->unique();
            $table->unsignedBigInteger('companies_id');
            $table->unsignedInteger('apps_id');
            $table->unsignedBigInteger('users_id')->default(0);
            $table->unsignedInteger('companies_actions_id');
            $table->unsignedInteger('message_id');
            $table->unsignedInteger('leads_id');
            $table->unsignedBigInteger('pipelines_stages_id');
            $table->char('entity_uuid', 64);
            $table->string('slug', 64);
            $table->dateTime('created_at');
            $table->dateTime('updated_at')->nullable();
            $table->boolean('is_deleted')->default(false);
            $table->index('message_id');
            $table->index('pipelines_stages_id');
            $table->index('entity_uuid');
            $table->index('apps_id');
            $table->index('companies_id');
            $table->index('slug');
            $table->index('leads_id');
            $table->index('users_id');
        });

        // Create hooks table
        Schema::create('hooks', function (Blueprint $table) {
            $table->increments('id');
            $table->char('uuid', 36)->unique();
            $table->unsignedBigInteger('companies_id');
            $table->unsignedBigInteger('companies_branches_id');
            $table->unsignedBigInteger('users_id');
            $table->unsignedBigInteger('system_modules_id');
            $table->unsignedBigInteger('sources_id');
            $table->string('name', 150);
            $table->boolean('is_active')->default(false);
            $table->longText('template')->nullable();
            $table->dateTime('created_at');
            $table->dateTime('updated_at')->nullable();
            $table->boolean('is_deleted')->default(false);
            $table->index('companies_id');
            $table->index('users_id');
            $table->index('system_modules_id');
            $table->index('sources_id');
            $table->index('companies_branches_id');
        });

        // Create hooks_logs table
        Schema::create('hooks_logs', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('hooks_id');
            $table->char('entity_id', 50)->nullable();
            $table->char('hook_type', 1)->nullable();
            $table->char('entity_namespace', 150)->nullable();
            $table->tinyInteger('status')->default(0);
            $table->longText('request_template')->nullable();
            $table->longText('request')->nullable();
            $table->longText('results')->nullable();
            $table->longText('error')->nullable();
            $table->dateTime('created_at');
            $table->dateTime('updated_at');
            $table->boolean('is_deleted')->default(false);
            $table->index('hooks_id');
            $table->index('status');
        });

        // Create hooks_source_module_settings table
        Schema::create('hooks_source_module_settings', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedBigInteger('sources_id');
            $table->unsignedBigInteger('system_modules_id');
            $table->string('source_entity_primary_key', 150);
            $table->string('entity_custom_field_primary_key', 150);
            $table->string('name', 150);
            $table->longText('template')->nullable();
            $table->dateTime('created_at');
            $table->dateTime('updated_at')->nullable();
            $table->boolean('is_deleted')->default(false);
            $table->unique(['sources_id', 'system_modules_id']);
            $table->index('created_at');
            $table->index('updated_at');
        });

        // Create marketplace_apps table
        Schema::create('marketplace_apps', function (Blueprint $table) {
            $table->increments('id');
            $table->char('uuid', 36)->unique();
            $table->unsignedInteger('apps_id');
            $table->unsignedBigInteger('users_id');
            $table->enum('type', ['KEY', 'OAUTH']);
            $table->unsignedInteger('marketplaces_categories_id');
            $table->string('name', 150);
            $table->char('slug', 250);
            $table->text('description')->nullable();
            $table->longText('configuration')->nullable();
            $table->boolean('is_active')->default(false);
            $table->dateTime('created_at');
            $table->dateTime('updated_at')->nullable();
            $table->boolean('is_deleted')->default(false);
            $table->index('is_active');
            $table->index('created_at');
            $table->index('updated_at');
            $table->index('uuid');
            $table->index('apps_id');
            $table->index('slug');
            $table->index('users_id');
            $table->index('marketplaces_categories_id');
        });

        // Create marketplace_categories table
        Schema::create('marketplace_categories', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name', 150);
            $table->dateTime('created_at');
            $table->dateTime('updated_at')->nullable();
            $table->boolean('is_deleted')->default(false);
            $table->index('created_at');
            $table->index('updated_at');
        });

        // Create pipelines table
        Schema::create('pipelines', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('companies_id')->nullable();
            $table->unsignedBigInteger('users_id');
            $table->string('name', 64)->nullable();
            $table->string('slug', 64)->nullable();
            $table->smallInteger('weight')->nullable();
            $table->dateTime('created_at');
            $table->dateTime('updated_at')->nullable();
            $table->boolean('is_default')->default(false);
            $table->boolean('is_deleted')->default(false);
            $table->index('companies_id');
            $table->index('weight');
            $table->index('users_id');
            $table->index('slug');
            $table->index('is_default');
            $table->index('is_deleted');
        });

        // Create pipelines_stages table
        Schema::create('pipelines_stages', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('pipelines_id');
            $table->string('name', 64);
            $table->char('slug', 64);
            $table->boolean('has_rotting_days')->default(false);
            $table->tinyInteger('rotting_days')->default(0);
            $table->decimal('weight', 10, 2)->default(0.00);
            $table->dateTime('created_at');
            $table->dateTime('updated_at')->nullable();
            $table->boolean('is_deleted')->default(false);
            $table->index('pipelines_id');
        });

        // Create pipelines_stages_messages table
        Schema::create('pipelines_stages_messages', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('pipelines_stages_id');
            $table->text('message');
            $table->text('message_notification')->nullable();
            $table->dateTime('created_at');
            $table->dateTime('updated_at')->nullable();
            $table->boolean('is_deleted')->default(false);
            $table->index('pipelines_stages_id');
        });
    }

    public function down()
    {
        Schema::dropIfExists('actions');
        Schema::dropIfExists('actions_custom_forms');
        Schema::dropIfExists('business_verticals');
        Schema::dropIfExists('business_verticals_actions');
        Schema::dropIfExists('companies_actions');
        Schema::dropIfExists('companies_actions_visitors');
        Schema::dropIfExists('companies_marketplace_apps');
        Schema::dropIfExists('engagements');
        Schema::dropIfExists('hooks');
        Schema::dropIfExists('hooks_logs');
        Schema::dropIfExists('hooks_source_module_settings');
        Schema::dropIfExists('marketplace_apps');
        Schema::dropIfExists('marketplace_categories');
        Schema::dropIfExists('pipelines');
        Schema::dropIfExists('pipelines_stages');
        Schema::dropIfExists('pipelines_stages_messages');
    }
};
