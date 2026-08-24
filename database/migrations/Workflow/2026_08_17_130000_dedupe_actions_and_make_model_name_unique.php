<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `actions` is keyed on `model_name` by every writer — the sync command looks a handler up by it, and
 * the catalog lists one entry per handler — but nothing enforced that, and duplicates accumulated
 * (one handler reached ~1850 rows). Duplicates split a handler's live wiring across rows and bury the
 * catalog an agent reads, so the column becomes unique here.
 *
 * The rows cannot simply be deleted: live records point at the copies, not at the survivor. Each
 * referencing column is remapped onto the surviving row first, and `rules_workflow_actions` has a real
 * foreign key, so the order matters — remap, then delete, then add the constraint.
 *
 * Survivor is the lowest id per handler, matching what the sync command maintains.
 */
return new class () extends Migration {
    protected $connection = 'workflow';

    private const string INDEX = 'actions_model_name_unique';

    /**
     * Every column holding an `actions.id`. Missing one would strand the rows it points at.
     *
     * @var list<array{0: string, 1: string}>
     */
    private const array REFERENCES = [
        ['integrations', 'actions_id'],
        ['integrations', 'receivers_id'],
        ['receiver_webhooks', 'action_id'],
        ['rules_workflow_actions', 'actions_id'],
        ['workflows_logs_actions', 'actions_id'],
    ];

    public function up(): void
    {
        $connection = DB::connection('workflow');

        $duplicates = $connection->table('actions')
            ->select('model_name', DB::raw('MIN(id) as survivor'))
            ->groupBy('model_name')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        foreach ($duplicates as $duplicate) {
            $losers = $connection->table('actions')
                ->where('model_name', $duplicate->model_name)
                ->where('id', '!=', $duplicate->survivor)
                ->pluck('id')
                ->all();

            if ($losers === []) {
                continue;
            }

            foreach (array_chunk($losers, 500) as $chunk) {
                foreach (self::REFERENCES as [$table, $column]) {
                    if (! Schema::connection('workflow')->hasTable($table)) {
                        continue;
                    }

                    $connection->table($table)
                        ->whereIn($column, $chunk)
                        ->update([$column => $duplicate->survivor]);
                }

                $connection->table('actions')->whereIn('id', $chunk)->delete();
            }
        }

        if (! $this->hasUniqueIndex()) {
            $connection->statement(
                'ALTER TABLE `actions` ADD UNIQUE INDEX `' . self::INDEX . '` (`model_name`)'
            );
        }
    }

    public function down(): void
    {
        // The merged rows are not recoverable, so this only lifts the constraint.
        if ($this->hasUniqueIndex()) {
            DB::connection('workflow')->statement(
                'ALTER TABLE `actions` DROP INDEX `' . self::INDEX . '`'
            );
        }
    }

    private function hasUniqueIndex(): bool
    {
        $indexes = DB::connection('workflow')->select(
            'SHOW INDEX FROM `actions` WHERE Key_name = ?',
            [self::INDEX]
        );

        return $indexes !== [];
    }
};
