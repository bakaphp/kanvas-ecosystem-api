<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('users_day_offs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('apps_id');
            $table->unsignedBigInteger('companies_id');
            $table->unsignedBigInteger('users_id');
            $table->string('type', 32);
            // Both null = no end: a fixed weekly day off.
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            // Weekdays 0 (Sunday) to 6 (Saturday); null = every day in the range.
            $table->json('days')->nullable();
            $table->string('note')->nullable();
            $table->boolean('is_deleted')->default(false);
            $table->dateTime('created_at');
            $table->dateTime('updated_at')->nullable();

            $table->index(['users_id', 'companies_id', 'apps_id', 'is_deleted'], 'idx_users_day_offs_user');
            $table->index(['start_date', 'end_date'], 'idx_users_day_offs_dates');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('users_day_offs');
    }
};
