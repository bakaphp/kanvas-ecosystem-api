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
        Schema::create('receiver_webhooks', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('uuid', 36)->unique()->index();
            $table->unsignedBigInteger('apps_id')->index();
            $table->unsignedInteger('action_id')->index();
            $table->unsignedBigInteger('companies_id')->index();
            $table->unsignedBigInteger('users_id')->index();
            $table->string('name');
            $table->string('description')->nullable();
            $table->json('configuration')->nullable();
            $table->tinyInteger('is_active')->default(1)->index();
            $table->timestamps();
            $table->tinyInteger('is_deleted')->default(0)->index();

            //foreign keys with actions table
            //$table->foreign('action_id')->references('id')->on('actions')->onDelete('cascade');
            $table->index(['uuid', 'apps_id', 'companies_id'], 'webhooks_uuid_apps_index');
            $table->index(['uuid', 'apps_id'], 'webhooks_uuid_index');
        });

        Schema::create('receiver_webhook_calls', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('receiver_webhooks_id')->index();
            $table->string('uuid', 36)->unique()->index();
            $table->string('url');
            $table->json('headers')->nullable();
            $table->json('payload')->nullable();
            $table->json('results')->nullable();
            $table->json('exception')->nullable();
            $table->enum('status', ['pending', 'success', 'failed'])->default('pending');
            $table->timestamps();
            $table->tinyInteger('is_deleted')->default(0)->index();

            //foreign keys with webhooks table
            $table->foreign('receiver_webhooks_id')->references('id')->on('receiver_webhooks')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('receiver_webhook_calls');
        Schema::dropIfExists('receiver_webhooks');
    }
};
