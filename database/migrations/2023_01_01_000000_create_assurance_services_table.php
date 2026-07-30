<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assurance_services', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('apps_id');
            $table->unsignedInteger('companies_id');
            $table->unsignedInteger('users_id');
            $table->unsignedInteger('order_id'); // Assuming a link to an order
            $table->string('product');
            $table->string('service_type');
            $table->longText('payload'); // Storing the mixed payload as JSON
            $table->longText('response_data')->nullable(); // Storing the mixed response data as JSON
            $table->string('status')->default('pending');
            $table->string('external_id')->nullable(); // External ID from the assurance provider
            $table->timestamps();
            $table->softDeletes();
            $table->unsignedInteger('created_by')->nullable();
            $table->unsignedInteger('updated_by')->nullable();
            $table->unsignedInteger('last_interaction_by')->nullable();
            $table->boolean('is_deleted')->default(0);

            $table->foreign('apps_id')->references('id')->on('apps');
            $table->foreign('companies_id')->references('id')->on('companies');
            $table->foreign('users_id')->references('id')->on('users');
            // Assuming an 'orders' table exists
            // $table->foreign('order_id')->references('id')->on('orders');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assurance_services');
    }
};
