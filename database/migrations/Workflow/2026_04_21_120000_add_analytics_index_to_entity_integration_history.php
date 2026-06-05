<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::connection('workflow')->table('entity_integration_history', function (Blueprint $table) {
            $table->index(
                ['apps_id', 'companies_id', 'is_deleted', 'created_at'],
                'ix_eih_analytics',
            );
        });
    }

    public function down(): void
    {
        Schema::connection('workflow')->table('entity_integration_history', function (Blueprint $table) {
            $table->dropIndex('ix_eih_analytics');
        });
    }
};
