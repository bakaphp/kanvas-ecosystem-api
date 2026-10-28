<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    /**
     * Snapshot of LeadStatus::OPEN_STATUS_NAMES when this ran, inlined on purpose: a migration is
     * frozen, the model's list goes on changing with the tenants, and LeadStatusObserver covers
     * every row created after this one.
     */
    private const array OPEN_STATUS_NAMES = [
        'created',
        'active',
        'open',
        'new',
        'lead',
        'new lead',
        'engaged',
        'visit',
        'proposal',
        'hot',
        'follow up',
        '1st attempt',
        '2nd attempt',
        '3rd attempt',
        'attempted to contact',
    ];

    public function up(): void
    {
        Schema::connection('crm')->table('leads_status', function (Blueprint $table) {
            $table->boolean('is_open')->default(false)->after('is_default');
        });

        // Statuses 1 and 2 stay open so no lead changes state.
        DB::connection('crm')->table('leads_status')->whereIn('id', [1, 2])->update(['is_open' => true]);

        // Rows tenants created by name before the column existed: the dealer CRMs' Active / Open /
        // Lead, the finance pipelines' steps, and every company default. Same rule LeadStatus applies
        // to a row created from now on.
        DB::connection('crm')->table('leads_status')
            ->where(
                fn (QueryBuilder $query) => $query
                    ->whereIn(DB::raw('LOWER(name)'), self::OPEN_STATUS_NAMES)
                    ->orWhere('is_default', 1)
            )
            ->update(['is_open' => true]);
    }

    public function down(): void
    {
        Schema::connection('crm')->table('leads_status', function (Blueprint $table) {
            $table->dropColumn('is_open');
        });
    }
};
