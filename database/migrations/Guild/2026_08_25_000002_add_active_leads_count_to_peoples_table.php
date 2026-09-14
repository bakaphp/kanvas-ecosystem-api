<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Counter cache for "does this person have an open lead, anywhere" — replaces
 * a per-request correlated EXISTS/NOT EXISTS pair with a stored column
 * maintained by LeadObserver::syncActiveLeadsCount() on Lead create/status-
 * change/soft-delete/reassign/hard-delete.
 *
 * "Open" mirrors Lead::hasOpenLeadStatus() (leads_status_id IN (1, 2), the
 * two SalesAssist-active statuses) — NOT the `status` int column, which no
 * app code ever writes.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::connection('crm')->table('peoples', function (Blueprint $table) {
            $table->unsignedInteger('active_leads_count')->default(0)->index();
        });

        DB::connection('crm')->statement('
            UPDATE peoples p
            SET active_leads_count = (
                SELECT COUNT(*) FROM leads l
                WHERE l.people_id = p.id
                  AND l.leads_status_id IN (1, 2)
                  AND l.is_deleted = 0
            )
        ');
    }

    public function down(): void
    {
        Schema::connection('crm')->table('peoples', function (Blueprint $table) {
            $table->dropIndex(['active_leads_count']);
            $table->dropColumn('active_leads_count');
        });
    }
};
