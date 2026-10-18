<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The table is tens of GB in production, so the column and its index go in as one in-place ALTER
 * that never blocks writes. Idempotent, because production runs it by hand ahead of the deploy.
 */
return new class () extends Migration {
    private const string TABLE = 'entity_integration_history';

    public function up(): void
    {
        if (Schema::connection('workflow')->hasColumn(self::TABLE, 'actions_id')) {
            return;
        }

        DB::connection('workflow')->statement(
            'ALTER TABLE ' . self::TABLE
            . ' ADD COLUMN actions_id BIGINT UNSIGNED NULL AFTER rules_id,'
            . ' ADD INDEX entity_integration_history_actions_id_index (actions_id),'
            . ' ALGORITHM=INPLACE, LOCK=NONE'
        );
    }

    public function down(): void
    {
        if (! Schema::connection('workflow')->hasColumn(self::TABLE, 'actions_id')) {
            return;
        }

        DB::connection('workflow')->statement(
            'ALTER TABLE ' . self::TABLE
            . ' DROP INDEX entity_integration_history_actions_id_index,'
            . ' DROP COLUMN actions_id,'
            . ' ALGORITHM=INPLACE, LOCK=NONE'
        );
    }
};
