<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `peoples(hasLeads: ...)` runs a correlated EXISTS per candidate person — 124k probes on a
 * large tenant. With only the single-column people_id index each probe had to fall back to the
 * clustered row to read is_deleted and leads_status_id; the composite makes it covering.
 *
 * people_id is an exact prefix, so the old single-column index is dropped rather than kept as
 * dead write cost on a table the connectors write to constantly.
 */
return new class () extends Migration {
    private const string INDEX_NAME = 'leads_people_deleted_status_idx';

    public function up(): void
    {
        Schema::connection('crm')->table('leads', function (Blueprint $table) {
            $table->index(
                ['people_id', 'is_deleted', 'leads_status_id'],
                self::INDEX_NAME
            );
        });

        Schema::connection('crm')->table('leads', function (Blueprint $table) {
            $table->dropIndex('people_id');
        });
    }

    public function down(): void
    {
        Schema::connection('crm')->table('leads', function (Blueprint $table) {
            $table->index('people_id', 'people_id');
        });

        Schema::connection('crm')->table('leads', function (Blueprint $table) {
            $table->dropIndex(self::INDEX_NAME);
        });
    }
};
