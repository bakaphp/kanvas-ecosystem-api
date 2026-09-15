<?php

declare(strict_types=1);

namespace Kanvas\NervousSystem\Project\Actions;

use Illuminate\Support\Facades\DB;
use Kanvas\NervousSystem\Project\Models\Project;
use Kanvas\NervousSystem\Project\Support\ProjectBoardColumns;

class ReorderProjectBoardColumnsAction
{
    /**
     * @param array<int, string> $keys
     */
    public function __construct(
        protected readonly Project $project,
        protected readonly array $keys,
    ) {
    }

    /**
     * @return array<int, array{key: string, name: string, position: int, plan_status: string, legacy_statuses: array<int, string>}>
     */
    public function execute(): array
    {
        return DB::connection('intelligence')->transaction(function (): array {
            /** @var Project $project */
            $project = Project::query()
                ->whereKey($this->project->getId())
                ->lockForUpdate()
                ->firstOrFail();

            $columns = new ProjectBoardColumns()->reorder($project, $this->keys);
            $project->emitLedgerEvent('project.board_columns.reordered', payload: [
                'keys' => array_column($columns, 'key'),
            ]);

            return $columns;
        });
    }
}
