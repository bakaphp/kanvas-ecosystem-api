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
        Schema::create('event_resources', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->uuid('uuid')->index();
            $table->unsignedBigInteger('apps_id');
            $table->unsignedBigInteger('companies_id');
            $table->unsignedBigInteger('event_id');
            $table->unsignedBigInteger('resources_id');
            $table->string('resources_type');
            $table->json('metadata')->nullable();
            $table->boolean('is_deleted')->default(false)->index();
            $table->timestamps();

            $table->index(['event_id', 'resources_id', 'resources_type']);
            $table->index(['resources_id', 'resources_type']);
            $table->index(['apps_id', 'companies_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('event_resources');
    }
};
