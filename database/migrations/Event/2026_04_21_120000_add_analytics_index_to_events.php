<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::connection('event')->table('events', function (Blueprint $table) {
            $table->index(
                ['apps_id', 'companies_id', 'is_deleted', 'created_at'],
                'ix_events_analytics',
            );
        });
    }

    public function down(): void
    {
        Schema::connection('event')->table('events', function (Blueprint $table) {
            $table->dropIndex('ix_events_analytics');
        });
    }
};
