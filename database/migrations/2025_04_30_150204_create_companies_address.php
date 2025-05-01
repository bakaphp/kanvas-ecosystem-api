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
        Schema::create('companies_address', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('companies_id')->index('companies_id');
            $table->string('name')->nullable();
            $table->string('address')->nullable();
            $table->string('address_2')->nullable();
            $table->string('city')->nullable();
            $table->string('state')->nullable();
            $table->string('county')->nullable();
            $table->char('zip', 50)->nullable();
            $table->integer('countries_id')->nullable()->index('country_id');
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
        Schema::dropIfExists('companies_address');
    }
};
