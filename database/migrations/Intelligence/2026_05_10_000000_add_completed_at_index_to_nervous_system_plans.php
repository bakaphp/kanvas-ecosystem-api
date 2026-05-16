<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Composite index covering the dashboard-metrics aggregator's hot path:
 *   WHERE apps_id = ? AND companies_id = ?
 *     AND completed_at BETWEEN ? AND ?
 *     AND is_deleted = 0
 *
 * The existing apps_company_status_deadline index is (apps_id, companies_id,
 * status, deadline_at) — useful for status filtering but not for completed_at
 * range scans. Without this index, dashboard reads on tenants with many plans
 * would full-scan the partition.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::connection('intelligence')->table('nervous_system_plans', function (Blueprint $table) {
            $table->index(
                ['apps_id', 'companies_id', 'completed_at'],
                'apps_company_completed_at',
            );
        });
    }

    public function down(): void
    {
        Schema::connection('intelligence')->table('nervous_system_plans', function (Blueprint $table) {
            $table->dropIndex('apps_company_completed_at');
        });
    }
};
