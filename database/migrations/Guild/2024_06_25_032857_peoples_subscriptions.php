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
            $table->bigInteger('apps_id')->unsigned();
            $table->bigInteger('peoples_id')->unsigned();
            $table->string('subscription_type');
            $table->string('status');
            $table->date('first_date');
            $table->date('start_date');
            $table->date('end_date')->nullable();
            $table->date('next_renewal')->nullable();
            $table->text('metadata')->nullable();
            $table->boolean('is_deleted')->default(0);
            $table->dateTime('created_at')->index('created_at');
            $table->dateTime('updated_at')->nullable()->index('updated_at');
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
