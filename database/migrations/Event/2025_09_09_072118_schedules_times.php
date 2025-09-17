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
        Schema::create('time_slots', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->bigInteger('apps_id')->index();
            $table->bigInteger('companies_id')->index();
            $table->unsignedBigInteger('resources_id')->index();
            $table->string('resources_type', 255)->index();
            $table->dateTime('start_at');           // UTC
            $table->dateTime('end_at');             // UTC
            $table->unsignedSmallInteger('capacity');
            $table->enum('status', ['open','held','booked','blocked'])->default('open')->index(); // opcional derivar
            $table->decimal('price_snapshot', 15, 2)->nullable();
            $table->string('currency', 3)->default('USD');
            $table->json('meta')->nullable();
            $table->boolean('is_deleted')->default(false)->index();
            $table->timestamps();
            $table->unique(['resources_id', 'resources_type', 'start_at']);
            $table->index(['resources_id', 'resources_type', 'start_at']);
        });

        Schema::create('event_version_slots', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->bigInteger('apps_id')->index();
            $table->bigInteger('companies_id')->index();
            $table->unsignedBigInteger('event_version_id')->index();
            $table->unsignedBigInteger('time_slots_id')->index();
            $table->unsignedSmallInteger('qty')->default(1);
            $table->boolean('is_deleted')->default(false)->index();
            $table->timestamps();
            $table->unique(['event_version_id','time_slots_id']);
        });

        Schema::table('events', function (Blueprint $table) {
            $table->unsignedBigInteger('resources_id')->nullable()->index();
            $table->string('resources_type', 255)->nullable()->index();
            $table->dateTime('start_at')->nullable();
            $table->dateTime('end_at')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('time_slots');
        Schema::dropIfExists('event_version_slots');
    }
};
