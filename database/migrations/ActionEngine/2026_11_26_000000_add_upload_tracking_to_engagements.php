<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::connection('action_engine')->table('engagements', function (Blueprint $table): void {
            $table->unsignedInteger('upload_count')->default(0);
            $table->unsignedInteger('upload_limit')->default(0);
            $table->dateTime('upload_expires_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::connection('action_engine')->table('engagements', function (Blueprint $table): void {
            $table->dropColumn(['upload_count', 'upload_limit', 'upload_expires_at']);
        });
    }
};
