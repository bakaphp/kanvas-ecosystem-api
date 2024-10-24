<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up()
    {
        // Create tables without foreign keys
        Schema::create('event_types', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('companies_id')->index();
            $table->unsignedBigInteger('apps_id')->index();
            $table->unsignedBigInteger('users_id')->index();
            $table->string('name', 255);
            $table->boolean('is_deleted')->default(false)->index();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['companies_id', 'apps_id']);
            $table->index(['apps_id', 'companies_id', 'is_deleted'], 'event_types_full_idx');
        });

        Schema::create('event_classes', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('companies_id')->index();
            $table->unsignedBigInteger('apps_id')->index();
            $table->unsignedBigInteger('users_id')->index();
            $table->string('name', 255);
            $table->tinyInteger('is_default')->default(0)->index();
            $table->boolean('is_deleted')->default(false)->index();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['companies_id', 'apps_id']);
            $table->index(['apps_id', 'companies_id', 'is_deleted'], 'event_classes_full_idx');
        });

        Schema::create('event_categories', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('companies_id')->index();
            $table->unsignedBigInteger('apps_id')->index();
            $table->unsignedBigInteger('event_type_id')->index();
            $table->unsignedBigInteger('event_class_id')->index();
            $table->unsignedBigInteger('users_id')->index();
            $table->string('name', 255);
            $table->unsignedBigInteger('parent_id')->nullable()->index();
            $table->string('path')->nullable()->index();
            $table->string('slug')->index(); // Slug with unique index
            $table->integer('position')->default(0)->index();
            $table->tinyInteger('is_default')->default(0)->index();
            $table->boolean('is_deleted')->default(false)->index();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['companies_id', 'apps_id', 'event_type_id', 'event_class_id'], 'event_cat_idx');
            $table->index(['apps_id', 'companies_id', 'is_deleted'], 'event_categories_full_idx');
        });

        Schema::create('themes', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('companies_id')->index();
            $table->unsignedBigInteger('apps_id')->index();
            $table->unsignedBigInteger('users_id')->index();
            $table->string('name', 255);
            $table->tinyInteger('is_default')->default(0)->index();
            $table->boolean('is_deleted')->default(false)->index();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['companies_id', 'apps_id']);
            $table->index(['apps_id', 'companies_id', 'is_deleted'], 'themes_full_idx');
        });

        Schema::create('theme_areas', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('companies_id')->index();
            $table->unsignedBigInteger('apps_id')->index();
            $table->unsignedBigInteger('users_id')->index();
            $table->string('name', 255);
            $table->tinyInteger('is_default')->default(0)->index();
            $table->boolean('is_deleted')->default(false)->index();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['companies_id', 'apps_id']);
            $table->index(['apps_id', 'companies_id', 'is_deleted'], 'theme_areas_full_idx');
        });

        Schema::create('participant_pass_motives', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('apps_id')->index();
            $table->unsignedBigInteger('companies_id')->index();
            $table->unsignedBigInteger('users_id')->index();
            $table->string('name', 255);
            $table->boolean('is_deleted')->default(false)->index();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['companies_id', 'apps_id']);
            $table->index(['apps_id', 'companies_id', 'users_id'], 'participant_pass_motives_idx');
            $table->index(['apps_id', 'companies_id', 'is_deleted'], 'participant_pass_motives_full_idx');
        });

        Schema::create('events', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->index();
            $table->unsignedBigInteger('users_id')->index();
            $table->unsignedBigInteger('companies_id')->index();
            $table->unsignedBigInteger('apps_id')->index();
            $table->unsignedBigInteger('theme_id')->index();
            $table->unsignedBigInteger('theme_area_id')->index();
            $table->unsignedBigInteger('event_status_id')->index();
            $table->unsignedBigInteger('event_type_id')->index();
            $table->unsignedBigInteger('event_class_id')->index();
            $table->unsignedBigInteger('event_category_id')->index();
            $table->string('name', 255);
            $table->string('slug')->index(); // Slug with unique index
            $table->string('classification', 255)->nullable();
            $table->text('description')->nullable();
            $table->integer('versions_count')->default(0);
            $table->string('participants_average', 255)->nullable();
            $table->string('participants_satisfaction', 255)->nullable();
            $table->boolean('is_deleted')->default(false)->index();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['companies_id', 'apps_id']);
            $table->unique(['slug', 'apps_id', 'companies_id'], 'event_slug_unique'); // Ensure uniqueness
            $table->index(['users_id', 'companies_id', 'apps_id', 'theme_id', 'theme_area_id', 'event_status_id', 'event_type_id', 'event_class_id', 'event_category_id'], 'events_idx');
            $table->index(['apps_id', 'companies_id', 'is_deleted'], 'events_full_idx');
        });

        Schema::create('event_versions', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->index();
            $table->unsignedBigInteger('companies_id')->index();
            $table->unsignedBigInteger('users_id')->index();
            $table->unsignedBigInteger('apps_id')->index();
            $table->unsignedBigInteger('currency_id')->index();
            $table->unsignedBigInteger('event_id')->index();
            $table->string('name', 255);
            $table->integer('version_number')->default(1);
            $table->string('version', 255);
            $table->string('slug')->index(); // Slug with unique index
            $table->text('description')->nullable();
            $table->string('classification', 255)->nullable();
            $table->string('places_comments', 255)->nullable();
            $table->string('participants_satisfaction', 255)->nullable();
            $table->float('price_per_ticket');
            $table->json('agenda')->nullable();
            $table->json('metadata')->nullable();
            $table->boolean('is_deleted')->default(false)->index();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['slug', 'apps_id', 'companies_id'], 'event_version_slug_unique'); // Ensure uniqueness
            $table->index(['companies_id', 'apps_id']);
            $table->index(['companies_id', 'users_id', 'apps_id', 'event_id'], 'event_versions_idx');
            $table->index(['apps_id', 'companies_id', 'is_deleted'], 'event_versions_full_idx');
        });

        Schema::create('event_statuses', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('companies_id')->index();
            $table->unsignedBigInteger('apps_id')->index();
            $table->unsignedBigInteger('users_id')->index();
            $table->string('name', 255);
            $table->tinyInteger('is_default')->default(0)->index();
            $table->boolean('is_deleted')->default(false)->index();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['companies_id', 'apps_id']);
            $table->index(['apps_id', 'companies_id', 'is_deleted'], 'event_statuses_full_idx');
        });

        Schema::create('participants', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->index();
            $table->unsignedBigInteger('theme_area_id')->index();
            $table->unsignedBigInteger('participant_status_id')->index();
            $table->unsignedBigInteger('apps_id')->index();
            $table->unsignedBigInteger('companies_id')->index();
            $table->unsignedBigInteger('users_id')->index();
            $table->unsignedBigInteger('people_id')->index();
            $table->string('general_representative', 255);
            $table->string('slug')->index(); // Slug with unique index
            $table->boolean('is_prospect');
            $table->boolean('is_deleted')->default(false)->index();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['slug', 'apps_id', 'companies_id'], 'participant_slug_unique'); // Ensure uniqueness
            $table->index(['companies_id', 'apps_id']);
            $table->index(['theme_area_id', 'participant_status_id', 'apps_id', 'companies_id', 'users_id', 'people_id'], 'participants_idx');
            $table->index(['apps_id', 'companies_id', 'is_deleted'], 'participants_full_idx');
        });

        Schema::create('event_version_dates', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('event_version_id')->index();
            $table->unsignedBigInteger('users_id')->index();
            $table->date('event_date')->index();
            $table->time('start_time')->index();
            $table->time('end_time')->index();
            $table->boolean('is_deleted')->default(false)->index();
            $table->timestamps();
            $table->softDeletes();
            $table->index('event_version_id', 'event_version_dates_idx');
            $table->index(['event_version_id', 'event_date']);
        });

        Schema::create('event_version_participants', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('event_version_id')->index();
            $table->unsignedBigInteger('participant_id')->index();
            $table->float('ticket_price');
            $table->float('discount');
            $table->date('invoice_date');
            $table->json('metadata')->nullable();
            $table->unsignedBigInteger('participant_type_id')->index();
            $table->boolean('is_deleted')->default(false)->index();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['event_version_id', 'participant_id', 'participant_type_id'], 'event_version_participants_idx');
        });

        Schema::create('participant_types', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('apps_id')->index();
            $table->unsignedBigInteger('companies_id')->index();
            $table->unsignedBigInteger('users_id')->index();
            $table->string('name', 255);
            $table->boolean('is_deleted')->default(false)->index();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['companies_id', 'apps_id']);
            $table->index(['apps_id', 'companies_id', 'users_id']);
            $table->index(['apps_id', 'companies_id', 'is_deleted'], 'participant_types_full_idx');
        });

        Schema::create('participant_passes', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('event_version_id')->index();
            $table->unsignedBigInteger('event_id')->index();
            $table->unsignedBigInteger('participant_id')->index();
            $table->unsignedBigInteger('participant_pass_motive_id')->index();
            $table->unsignedBigInteger('apps_id')->index();
            $table->unsignedBigInteger('companies_id')->index();
            $table->unsignedBigInteger('users_id')->index();
            $table->date('expiration_date');
            $table->date('used_date');
            $table->string('code', 255);
            $table->boolean('is_deleted')->default(false)->index();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['event_version_id', 'event_id', 'participant_id', 'participant_pass_motive_id', 'apps_id', 'companies_id', 'users_id'], 'participant_passes_idx');
            $table->index(['apps_id', 'companies_id', 'is_deleted'], 'participant_passes_full_idx');
        });

        Schema::create('event_version_date_participants', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('event_version_date_id')->index();
            $table->unsignedBigInteger('event_version_id')->index();
            $table->unsignedBigInteger('participant_id')->index();
            $table->datetime('arrived');
            $table->boolean('is_deleted')->default(false)->index();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['event_version_date_id', 'participant_id'], 'event_version_date_participants_idx');
            $table->index(['event_version_date_id', 'event_version_id', 'participant_id'], 'event_version_date_participants_idx2');
            $table->index(['event_version_date_id', 'event_version_id'], 'event_version_date_participants_idx3');
            $table->index(['participant_id', 'event_version_id'], 'event_version_date_participants_idx4');
        });

        Schema::create('facilitators', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->index();
            $table->unsignedBigInteger('apps_id')->index();
            $table->unsignedBigInteger('companies_id')->index();
            $table->unsignedBigInteger('users_id')->index();
            $table->unsignedBigInteger('people_id')->index();
            $table->string('slug')->index(); // Slug with unique index
            $table->string('identification', 255)->nullable();
            $table->text('resume')->nullable();
            $table->text('description')->nullable();
            $table->boolean('is_deleted')->default(false)->index();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['slug', 'apps_id', 'companies_id'], 'facilitator_slug_unique'); // Ensure uniqueness
            $table->index(['companies_id', 'apps_id']);
            $table->index(['apps_id', 'companies_id', 'users_id']);
            $table->index(['apps_id', 'companies_id', 'is_deleted'], 'facilitators_full_idx');
        });

        Schema::create('event_version_facilitators', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('facilitator_id')->index();
            $table->unsignedBigInteger('event_version_id')->index();
            $table->boolean('is_deleted')->default(false)->index();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['facilitator_id', 'event_version_id'], 'event_version_facilitators_idx');
        });

        // Add foreign keys after all tables are created
        Schema::table('event_categories', function (Blueprint $table) {
            $table->foreign('event_type_id')->references('id')->on('event_types');
            $table->foreign('event_class_id')->references('id')->on('event_classes');
        });

        Schema::table('events', function (Blueprint $table) {
            $table->foreign('theme_id')->references('id')->on('themes');
            $table->foreign('theme_area_id')->references('id')->on('theme_areas');
            $table->foreign('event_status_id')->references('id')->on('event_statuses');
            $table->foreign('event_type_id')->references('id')->on('event_types');
            $table->foreign('event_class_id')->references('id')->on('event_classes');
            $table->foreign('event_category_id')->references('id')->on('event_categories');
        });

        Schema::table('event_versions', function (Blueprint $table) {
            $table->foreign('event_id')->references('id')->on('events');
        });

        Schema::table('event_version_dates', function (Blueprint $table) {
            $table->foreign('event_version_id')->references('id')->on('event_versions');
        });

        Schema::table('event_version_participants', function (Blueprint $table) {
            $table->foreign('event_version_id')->references('id')->on('event_versions');
            $table->foreign('participant_id')->references('id')->on('participants');
            $table->foreign('participant_type_id')->references('id')->on('participant_types');
        });

        Schema::table('participant_passes', function (Blueprint $table) {
            $table->foreign('event_version_id')->references('id')->on('event_versions');
            $table->foreign('event_id')->references('id')->on('events');
            $table->foreign('participant_id')->references('id')->on('participants');
            $table->foreign('participant_pass_motive_id')->references('id')->on('participant_pass_motives');
        });

        Schema::table('event_version_date_participants', function (Blueprint $table) {
            $table->foreign('event_version_date_id')->references('id')->on('event_version_dates');
            $table->foreign('participant_id')->references('id')->on('participants');
            $table->foreign('event_version_id')->references('id')->on('event_versions');
        });

        Schema::table('event_version_facilitators', function (Blueprint $table) {
            $table->foreign('facilitator_id')->references('id')->on('facilitators');
            $table->foreign('event_version_id')->references('id')->on('event_versions');
        });
    }

    public function down()
    {
        // Drop foreign keys first
        Schema::table('event_version_facilitators', function (Blueprint $table) {
            $table->dropForeign(['facilitator_id']);
            $table->dropForeign(['event_version_id']);
        });

        Schema::table('event_version_date_participants', function (Blueprint $table) {
            $table->dropForeign(['event_version_date_id']);
            $table->dropForeign(['participant_id']);
        });

        Schema::table('participant_passes', function (Blueprint $table) {
            $table->dropForeign(['event_version_id']);
            $table->dropForeign(['event_id']);
            $table->dropForeign(['participant_id']);
            $table->dropForeign(['participant_pass_motive_id']);
        });

        Schema::table('event_version_participants', function (Blueprint $table) {
            $table->dropForeign(['event_version_id']);
            $table->dropForeign(['participant_id']);
            $table->dropForeign(['participant_type_id']);
        });

        Schema::table('event_version_dates', function (Blueprint $table) {
            $table->dropForeign(['event_version_id']);
        });

        Schema::table('event_versions', function (Blueprint $table) {
            $table->dropForeign(['event_id']);
        });

        Schema::table('events', function (Blueprint $table) {
            $table->dropForeign(['theme_id']);
            $table->dropForeign(['theme_area_id']);
            $table->dropForeign(['event_status_id']);
            $table->dropForeign(['event_type_id']);
            $table->dropForeign(['event_class_id']);
            $table->dropForeign(['event_category_id']);
        });

        Schema::table('event_categories', function (Blueprint $table) {
            $table->dropForeign(['event_type_id']);
            $table->dropForeign(['event_class_id']);
        });

        // Drop tables
        Schema::dropIfExists('event_version_facilitators');
        Schema::dropIfExists('facilitators');
        Schema::dropIfExists('event_version_date_participants');
        Schema::dropIfExists('participant_passes');
        Schema::dropIfExists('participant_types');
        Schema::dropIfExists('event_version_participants');
        Schema::dropIfExists('event_version_dates');
        Schema::dropIfExists('participants');
        Schema::dropIfExists('event_statuses');
        Schema::dropIfExists('event_versions');
        Schema::dropIfExists('events');
        Schema::dropIfExists('participant_pass_motives');
        Schema::dropIfExists('theme_areas');
        Schema::dropIfExists('themes');
        Schema::dropIfExists('event_categories');
        Schema::dropIfExists('event_classes');
        Schema::dropIfExists('event_types');
    }
};
