<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::connection('event')->create('schedule_history', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('apps_id')->unsigned()->index();
            $table->bigInteger('companies_id')->unsigned()->index();
            $table->bigInteger('users_id')->unsigned()->index();
            $table->bigInteger('resources_id')->unsigned();
            $table->string('resources_type');
            $table->string('action');
            $table->json('payload')->nullable();
            $table->boolean('is_deleted')->default(0);
            $table->timestamps();
            $table->index(['resources_id', 'resources_type']);
        });
    }

    public function down(): void
    {
        Schema::connection('event')->dropIfExists('schedule_history');
    }
};
