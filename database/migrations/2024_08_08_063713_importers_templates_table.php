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
        Schema::create('mappers_importers_templates', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('apps_id')->unsigned();
            $table->bigInteger('users_id')->unsigned();
            $table->bigInteger('companies_id')->unsigned();
            $table->string('name');
            $table->string('description')->nullable();
            $table->integer('is_deleted')->default(0);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('importers_templates');
    }
};
