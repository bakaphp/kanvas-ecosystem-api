<?php

use Illuminate\Database\Connection;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Making leads_id nullable rebuilds the table, so production runs this ahead of the deploy. The column keeps the
 * type it has: production's leads_id is a signed int, and spelling it INT UNSIGNED turned the nullability change
 * into a type change, which MySQL refuses INPLACE (1846). LOCK=NONE still takes brief metadata locks. Each part
 * is idempotent, so the deploy records an ALTER already applied by hand, in full or in part.
 */
return new class () extends Migration {
    private const string TABLE = 'engagements';

    public function up(): void
    {
        $db = DB::connection('action_engine');
        $parts = [];

        if (! Schema::connection('action_engine')->hasColumn(self::TABLE, 'entity_type')) {
            $parts[] = 'ADD COLUMN entity_type VARCHAR(64) NULL AFTER people_id';
            $parts[] = 'ADD COLUMN entity_id BIGINT UNSIGNED NULL AFTER entity_type';
            $parts[] = 'ADD INDEX engagements_entity_type_entity_id_index (entity_type, entity_id)';
            $parts[] = 'ADD INDEX engagements_companies_id_entity_type_entity_id_index (companies_id, entity_type, entity_id)';
        }

        $leadsId = $this->column($db, 'leads_id');

        if ($leadsId !== null && $leadsId->IS_NULLABLE === 'NO') {
            $parts[] = 'MODIFY leads_id ' . $leadsId->COLUMN_TYPE . ' NULL';
        }

        if ($parts === []) {
            return;
        }

        $db->statement('ALTER TABLE ' . self::TABLE . ' ' . implode(', ', $parts) . ', ALGORITHM=INPLACE, LOCK=NONE');
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

    private function column(Connection $db, string $name): ?object
    {
        return $db->selectOne(
            'SELECT COLUMN_TYPE, IS_NULLABLE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$db->getDatabaseName(), self::TABLE, $name],
        );
    }
};
