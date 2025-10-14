<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('event_holds', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('resources_id');
            $table->string('resources_type');
            $table->dateTime('start_at');
            $table->dateTime('end_at');
            $table->unsignedBigInteger('users_id');
            $table->unsignedBigInteger('companies_id')->index();
            $table->unsignedBigInteger('apps_id')->index();
            $table->dateTime('expires_at')->index();
            $table->boolean('is_deleted')->default(false)->index();
            $table->timestamps();

            $table->index(['resources_id', 'resources_type', 'start_at', 'end_at']);
            $table->index(['companies_id', 'apps_id', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_holds');
    }
};
