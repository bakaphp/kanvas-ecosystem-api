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
        Schema::create('carts', function (Blueprint $table) {
            $table->increments('id');
            $table->char('uuid', 36)->nullable()->index('uuid');
            $table->integer('apps_id')->index('apps_id');
            $table->integer('companies_id')->index('companies_id');
            $table->integer('users_id')->nullable()->index('users_id');
            $table->string('session_id')->nullable()->index('session_id');
            $table->string('email')->nullable();
            $table->string('payment_intent_id')->nullable()->index('payment_intent_id');
            $table->string('client_secret')->nullable();
            $table->decimal('amount', 10, 2)->nullable();
            $table->string('currency', 3)->default('usd');
            $table->string('status', 50)->default('pending')->index('status');
            $table->text('metadata')->nullable();
            $table->integer('is_deleted')->nullable()->default(0)->index('is_deleted');
            $table->dateTime('created_at')->nullable()->index('created_at');
            $table->dateTime('updated_at')->nullable()->index('updated_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('carts');
    }
};
