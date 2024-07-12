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
        Schema::create('peoples_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('apps_id')->unsigned()->index();
            $table->bigInteger('peoples_id')->unsigned()->index();
            $table->string('subscription_type')->index();
            $table->string('status')->index();
            $table->date('first_date');
            $table->date('start_date');
            $table->date('end_date')->nullable()->index();
            $table->date('next_renewal')->nullable()->index();
            $table->json('metadata')->nullable();
            $table->boolean('is_deleted')->default(0)->index();
            $table->dateTime('created_at')->index('created_at');
            $table->dateTime('updated_at')->nullable()->index('updated_at');

            //index apps people
            $table->index(['apps_id', 'peoples_id']);
            $table->index(['peoples_id', 'subscription_type']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('peoples_subscriptions');
    }
};
