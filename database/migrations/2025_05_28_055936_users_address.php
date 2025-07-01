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
        Schema::create('users_address', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('users_id')->index('users_id');
            $table->unsignedBigInteger('apps_id')->index('apps_id');
            $table->unsignedBigInteger('country_id')->index('country_id');
            $table->string('fullname');
            $table->string('phone')->nullable();
            $table->string('address');
            $table->string('address_2')->nullable();
            $table->string('city')->nullable();
            $table->string('county')->nullable();
            $table->string('state')->nullable();
            $table->string('zip')->nullable();
            $table->integer('city_id')->nullable()->index('city_id');
            $table->integer('state_id')->nullable()->index('state_id');
            $table->boolean('is_default')->default(false)->index('is_default');
            $table->boolean('is_deleted')->default(false)->index('is_deleted');
            $table->dateTime('created_at')->index('created_at');
            $table->dateTime('updated_at')->nullable()->index('updated_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('users_address');
    }
};
