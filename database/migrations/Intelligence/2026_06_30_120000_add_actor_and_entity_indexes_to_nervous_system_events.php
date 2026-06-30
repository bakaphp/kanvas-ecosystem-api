<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    protected $connection = 'intelligence';

    public function up(): void
    {
        Schema::connection('intelligence')->table('nervous_system_events', function (Blueprint $table) {
            $table->index(['actor_id'], 'actor_id_idx');
            $table->index(['source_entity_id'], 'source_entity_id_idx');
            $table->index(['companies_id'], 'companies_id_idx');
            $table->index(['apps_id'], 'apps_id_idx');
            $table->index(['apps_id', 'companies_id'], 'apps_companies_idx');
        });
    }

    public function down(): void
    {
        Schema::connection('intelligence')->table('nervous_system_events', function (Blueprint $table) {
            $table->dropIndex('actor_id_idx');
            $table->dropIndex('source_entity_id_idx');
            $table->dropIndex('companies_id_idx');
            $table->dropIndex('apps_id_idx');
            $table->dropIndex('apps_companies_idx');
        });
    }
};
