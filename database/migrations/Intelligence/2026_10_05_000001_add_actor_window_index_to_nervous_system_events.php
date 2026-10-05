<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `read_my_ledger` asks for one actor's newest events in a company over a time window. Without the
 * actor in a tenant-scoped index, MySQL walks `apps_company_occurred` backwards through every event
 * the company produced in the window, discarding other actors' rows until it has 50 keepers: 8 s on a
 * busy tenant. Run directly in production, not through Composer.
 */
return new class () extends Migration {
    protected $connection = 'intelligence';

    public function up(): void
    {
        Schema::connection('intelligence')->table('nervous_system_events', function (Blueprint $table) {
            $table->index(
                ['apps_id', 'companies_id', 'actor_type', 'actor_id', 'occurred_at'],
                'apps_company_actor_occurred'
            );
        });
    }

    public function down(): void
    {
        Schema::connection('intelligence')->table('nervous_system_events', function (Blueprint $table) {
            $table->dropIndex('apps_company_actor_occurred');
        });
    }
};
