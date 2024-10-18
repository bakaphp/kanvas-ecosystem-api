<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('blocked_users', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('users_id')->index();
            $table->unsignedBigInteger('blocked_users_id')->index();
            $table->unsignedBigInteger('apps_id')->index();
            $table->dateTime('created_at')->useCurrent()->index();
            $table->dateTime('updated_at')->useCurrent()->index();
            $table->boolean('is_deleted')->default(0)->nullable()->index();

            $table->index(['users_id', 'blocked_users_id', 'apps_id'], 'blocked_users_index');
            $table->index(['users_id', 'blocked_users_id', 'is_deleted']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('blocked_users');
    }
};
