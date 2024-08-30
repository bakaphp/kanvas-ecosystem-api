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
            $table->bigInteger('apps_id')->unsigned();
            $table->bigInteger('users_id')->unsigned();
            $table->bigInteger('companies_id')->unsigned();
            $table->bigInteger('regions_id')->unsigned();
            $table->bigInteger('companies_branches_id')->unsigned();
            $table->bigInteger('filesystem_id')->unsigned();
            $table->string('uuid');
            $table->integer('status')->default(0);
            $table->integer('is_deleted')->default(0);
            $table->timestamps();
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
