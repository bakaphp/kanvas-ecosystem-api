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
        Schema::create('importers_requests', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('apps_id')->unsigned()->index();
            $table->bigInteger('users_id')->unsigned()->index();
            $table->bigInteger('companies_id')->unsigned()->index();
            $table->bigInteger('regions_id')->unsigned()->index();
            $table->bigInteger('companies_branches_id')->unsigned()->index();
            $table->bigInteger('filesystem_id')->unsigned()->index();
            $table->string('uuid');
            $table->integer('status')->default(0)->index();
            $table->integer('is_deleted')->default(0)->index();
            $table->timestamps();

            $table->index(['apps_id', 'users_id', 'companies_id']);
            $table->index(['apps_id', 'companies_id']);
            $table->index(['apps_id', 'companies_id', 'is_deleted']);
            $table->index(['apps_id', 'companies_id', 'status']);
            $table->index(['apps_id', 'companies_id', 'regions_id']);
            $table->index(['apps_id', 'companies_id', 'regions_id', 'is_deleted']);
            $table->index(['apps_id', 'companies_id', 'regions_id', 'status']);
            $table->index(['apps_id', 'companies_id', 'regions_id', 'companies_branches_id']);
            $table->index(['apps_id', 'companies_id', 'regions_id', 'companies_branches_id', 'is_deleted']);
            $table->index(['apps_id', 'companies_id', 'regions_id', 'companies_branches_id', 'status']);
            $table->index(['apps_id', 'companies_id', 'regions_id', 'companies_branches_id', 'filesystem_id']);
            $table->index(['apps_id', 'companies_id', 'regions_id', 'companies_branches_id', 'filesystem_id', 'is_deleted']);
            $table->index(['apps_id', 'companies_id', 'regions_id', 'companies_branches_id', 'filesystem_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('importers_requests');
    }
};
