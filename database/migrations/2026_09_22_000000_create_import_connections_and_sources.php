<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('import_connections', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('apps_id');
            $table->unsignedBigInteger('companies_id')->default(0)->comment('0 = usable by every company in the app');
            $table->unsignedBigInteger('users_id');
            $table->string('name');
            $table->string('driver', 16);
            $table->string('host');
            $table->unsignedSmallInteger('port');
            $table->string('username');
            $table->text('password');
            $table->string('root')->nullable();
            $table->boolean('passive')->default(true);
            $table->string('default_schedule', 100)->nullable();
            $table->string('timezone', 64)->nullable();
            $table->boolean('is_deleted')->default(false);
            $table->timestamps();

            $table->index(['apps_id', 'companies_id', 'is_deleted']);
        });

        Schema::create('import_sources', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('apps_id');
            $table->unsignedBigInteger('companies_id');
            $table->unsignedBigInteger('companies_branches_id');
            $table->unsignedBigInteger('users_id');
            $table->unsignedBigInteger('regions_id');
            $table->unsignedBigInteger('filesystem_mapper_id');
            $table->unsignedBigInteger('import_connections_id');
            $table->unsignedBigInteger('warehouses_id')->nullable();
            $table->unsignedBigInteger('channels_id')->nullable();
            $table->string('name');
            $table->string('root')->nullable()->comment('Overrides the connection root');
            $table->json('files');
            $table->boolean('unpublish_missing')->default(false);
            $table->json('extra')->nullable();
            $table->string('schedule', 100)->nullable()->comment('Overrides the connection default_schedule');
            $table->string('timezone', 64)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_run_at')->nullable();
            $table->string('last_status', 16)->nullable();
            $table->text('last_message')->nullable();
            $table->unsignedBigInteger('last_filesystem_imports_id')->nullable();
            $table->boolean('is_deleted')->default(false);
            $table->timestamps();

            $table->index(['apps_id', 'companies_id', 'is_deleted']);
            $table->index(['is_active', 'is_deleted']);
            $table->index('import_connections_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('import_sources');
        Schema::dropIfExists('import_connections');
    }
};
