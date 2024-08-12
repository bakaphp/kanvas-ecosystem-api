<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('importer_requests', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('apps_id');
            $table->bigInteger('companies_id');
            $table->bigInteger('users_id');
            $table->string('job_uuid');
            $table->text('request');
            $table->int('products_count');
            $table->int('status');         
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('importer_requests');
    }
};
