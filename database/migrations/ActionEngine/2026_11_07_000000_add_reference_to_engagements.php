<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One in-place ALTER that never blocks writes: making leads_id nullable rebuilds the table, so
 * production runs this by hand ahead of the deploy. Idempotent for that reason.
 */
return new class () extends Migration {
    private const string TABLE = 'engagements';

    public function up(): void
    {
        if (Schema::connection('action_engine')->hasColumn(self::TABLE, 'entity_type')) {
            return;
        }

        DB::connection('action_engine')->statement(
            'ALTER TABLE ' . self::TABLE
            . ' ADD COLUMN entity_type VARCHAR(64) NULL AFTER people_id,'
            . ' ADD COLUMN entity_id BIGINT UNSIGNED NULL AFTER entity_type,'
            . ' MODIFY leads_id INT NULL,'
            . ' ADD INDEX engagements_entity_type_entity_id_index (entity_type, entity_id),'
            . ' ADD INDEX engagements_companies_id_entity_type_entity_id_index (companies_id, entity_type, entity_id),'
            . ' ALGORITHM=INPLACE, LOCK=NONE'
        );
    }

    public function down(): void
    {
        if (! Schema::connection('action_engine')->hasColumn(self::TABLE, 'entity_type')) {
            return;
        }

        DB::connection('action_engine')->statement(
            'ALTER TABLE ' . self::TABLE
            . ' DROP INDEX engagements_entity_type_entity_id_index,'
            . ' DROP INDEX engagements_companies_id_entity_type_entity_id_index,'
            . ' DROP COLUMN entity_type,'
            . ' DROP COLUMN entity_id,'
            . ' ALGORITHM=INPLACE, LOCK=NONE'
        );
    }
};
