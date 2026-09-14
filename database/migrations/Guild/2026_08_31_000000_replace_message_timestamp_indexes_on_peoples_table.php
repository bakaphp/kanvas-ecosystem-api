<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The single-column indexes added by 2026_05_29_000000 carry no tenant prefix, so
 * `peoples(orderBy: {column: LAST_MESSAGE_AT, order: DESC})` degenerates: MySQL picks the
 * bare index to avoid a filesort, then filters apps_id/companies_id row by row across
 * EVERY tenant. The column is also NULL for ~99.6% of rows, so the optimizer's "we'll fill
 * 25 rows early" bet fails immediately and it walks the whole index — measured at 754,633
 * rows scanned to return 4, ~1.9s, on a 754k-row copy.
 *
 * Prefixing with (apps_id, companies_id, is_deleted) confines the reverse scan to one
 * tenant while still satisfying the ORDER BY: 1,939ms -> 435ms on the pathological case,
 * 80ms -> 0.4ms on the common one. The bare indexes must go — with both present the
 * optimizer keeps choosing the unprefixed one (forcing the composite only reached 790ms).
 * Nothing else reads these columns: the sole writer, UpdatePeopleMessageTimestampsListener,
 * joins on leads.id.
 *
 * On a large peoples table this is an online ADD INDEX (INPLACE, LOCK=NONE) but still runs
 * for several minutes. The new indexes are created before the old ones are dropped so the
 * sort is never left without one.
 */
return new class () extends Migration {
    private const string LAST_INDEX = 'peoples_tenant_last_message_at_idx';
    private const string FIRST_INDEX = 'peoples_tenant_first_message_at_idx';

    public function up(): void
    {
        Schema::connection('crm')->table('peoples', function (Blueprint $table) {
            $table->index(
                ['apps_id', 'companies_id', 'is_deleted', 'last_message_at'],
                self::LAST_INDEX
            );
            $table->index(
                ['apps_id', 'companies_id', 'is_deleted', 'first_message_at'],
                self::FIRST_INDEX
            );
        });

        Schema::connection('crm')->table('peoples', function (Blueprint $table) {
            $table->dropIndex('peoples_last_message_at_index');
            $table->dropIndex('peoples_first_message_at_index');
        });
    }

    public function down(): void
    {
        Schema::connection('crm')->table('peoples', function (Blueprint $table) {
            $table->index('last_message_at', 'peoples_last_message_at_index');
            $table->index('first_message_at', 'peoples_first_message_at_index');
        });

        Schema::connection('crm')->table('peoples', function (Blueprint $table) {
            $table->dropIndex(self::LAST_INDEX);
            $table->dropIndex(self::FIRST_INDEX);
        });
    }
};
