<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The engine records every rule-driven activity run, and most activities have no integration
 * company (internal steps, dispatchers, notifications). Those rows carry the rule, the action and
 * the workflow but no integration.
 *
 * The table is tens of GB in production, so this is one in-place rebuild that never blocks writes:
 * both columns in a single ALTER, and the foreign keys dropped rather than re-added — re-adding them
 * is a copy that locks the table, and nothing reads them. Idempotent, because production ran it by
 * hand.
 */
return new class () extends Migration {
    private const string TABLE = 'entity_integration_history';

    private const array FOREIGN_KEYS = [
        'entity_integration_history_integrations_company_id_foreign',
        'entity_integration_history_integrations_id_foreign',
    ];

    public function up(): void
    {
        $db = DB::connection('workflow');

        foreach ($this->existingForeignKeys() as $foreignKey) {
            $db->statement('ALTER TABLE ' . self::TABLE . ' DROP FOREIGN KEY ' . $foreignKey);
        }

        if ($this->isNullable('integrations_company_id') && $this->isNullable('integrations_id')) {
            return;
        }

        $db->statement(
            'ALTER TABLE ' . self::TABLE
            . ' MODIFY integrations_company_id BIGINT UNSIGNED NULL,'
            . ' MODIFY integrations_id BIGINT UNSIGNED NULL,'
            . ' ALGORITHM=INPLACE, LOCK=NONE'
        );
    }

    /**
     * Not reversible on live data: engine-written rows legitimately have no integration, so NOT NULL
     * cannot be restored without deleting them.
     */
    public function down(): void
    {
    }

    /**
     * @return list<string>
     */
    private function existingForeignKeys(): array
    {
        return DB::connection('workflow')
            ->table('information_schema.TABLE_CONSTRAINTS')
            ->where('TABLE_SCHEMA', DB::connection('workflow')->getDatabaseName())
            ->where('TABLE_NAME', self::TABLE)
            ->where('CONSTRAINT_TYPE', 'FOREIGN KEY')
            ->whereIn('CONSTRAINT_NAME', self::FOREIGN_KEYS)
            ->pluck('CONSTRAINT_NAME')
            ->all();
    }

    private function isNullable(string $column): bool
    {
        return DB::connection('workflow')
            ->table('information_schema.COLUMNS')
            ->where('TABLE_SCHEMA', DB::connection('workflow')->getDatabaseName())
            ->where('TABLE_NAME', self::TABLE)
            ->where('COLUMN_NAME', $column)
            ->value('IS_NULLABLE') === 'YES';
    }
};
