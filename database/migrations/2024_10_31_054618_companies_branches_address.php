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
        Schema::create('companies_branches_address', function (Blueprint $table) {
            $table->id();
            $table->integer('companies_branches_id')->index('companies_branches_id');
            $table->string('address')->nullable();
            $table->string('address_2')->nullable();
            $table->string('city')->nullable();
            $table->string('state')->nullable();
            $table->char('zip', 50)->nullable();
            $table->integer('countries_id')->nullable()->index('country_id');
            $table->integer('states_id')->nullable()->index('state_id');
            $table->integer('cities_id')->nullable()->index('city_id');
            $table->tinyInteger('is_default')->default(1)->index('is_default');
            $table->boolean('is_deleted')->default(false)->index('is_deleted');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('companies_branches_address');
    }
};
