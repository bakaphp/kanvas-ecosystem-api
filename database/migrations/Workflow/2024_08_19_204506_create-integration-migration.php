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
        Schema::create('integrations', function (Blueprint $table) {
            $table->id();
            $table->string('name', 255);
            $table->uuid('uuid');
            $table->unsignedBigInteger('apps_id');
            $table->text('config')->nullable();
            $table->timestamps();
            $table->boolean('is_deleted')->default(false);

            $table->index(['uuid', 'apps_id'], 'integrations_uuid_apps_index');
        });

        // Create the status table
        Schema::create('status', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug');
            $table->unsignedBigInteger('apps_id');
            $table->timestamps();
            $table->boolean('is_deleted')->default(false);

            // You can add a foreign key constraint here if you have an apps table
            // $table->foreign('apps_id')->references('id')->on('apps');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('integrations');
    }
};
