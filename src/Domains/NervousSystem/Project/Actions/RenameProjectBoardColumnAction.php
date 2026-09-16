<?php

declare(strict_types=1);

namespace Kanvas\NervousSystem\Project\Actions;

use Illuminate\Support\Facades\DB;
use Kanvas\NervousSystem\Project\Models\Project;
use Kanvas\NervousSystem\Project\Support\ProjectBoardColumns;

/**
 * @phpstan-import-type BoardColumn from ProjectBoardColumns
 */
class RenameProjectBoardColumnAction
{
    public function __construct(
        protected readonly Project $project,
        protected readonly string $key,
        protected readonly string $name,
    ) {
    }

    /**
     * @return BoardColumn
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
