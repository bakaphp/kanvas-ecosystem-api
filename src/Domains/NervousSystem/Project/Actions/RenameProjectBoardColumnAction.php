<?php

declare(strict_types=1);

namespace Kanvas\NervousSystem\Project\Actions;

use Illuminate\Support\Facades\DB;
use Kanvas\NervousSystem\Project\Models\Project;
use Kanvas\NervousSystem\Project\Support\ProjectBoardColumns;

class RenameProjectBoardColumnAction
{
    public function __construct(
        protected readonly Project $project,
        protected readonly string $key,
        protected readonly string $name,
    ) {
    }

    /**
     * @return array{key: string, name: string, position: int, plan_status: string, legacy_statuses: array<int, string>}
     */
    public function execute(): array
    {
        return DB::connection('intelligence')->transaction(function (): array {
            /** @var Project $project */
            $project = Project::query()
                ->whereKey($this->project->getId())
                ->lockForUpdate()
                ->firstOrFail();

            $column = new ProjectBoardColumns()->rename($project, $this->key, $this->name);
            $project->emitLedgerEvent('project.board_column.renamed', payload: $column);

            return $column;
        });
    }
}
