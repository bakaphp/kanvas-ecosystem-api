<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Making leads_id nullable rebuilds the table, so production runs this ahead of the deploy.
 * Preserve its unsigned type for INPLACE support; LOCK=NONE still requires brief metadata locks.
 * Idempotent so the deploy can record an ALTER already applied by hand.
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
            . ' MODIFY leads_id INT UNSIGNED NULL,'
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
