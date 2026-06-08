<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Hermes\Kanban\Actions;

use Kanvas\Connectors\Hermes\Kanban\Traits\InteractsWithHermesKanbanCli;
use Kanvas\Connectors\Hermes\Services\HermesContainerCliService;
use Kanvas\Intelligence\AgentRuntime\DataTransferObject\KanbanTask;
use Kanvas\Intelligence\AgentRuntime\Enums\KanbanStatusEnum;
use Kanvas\Intelligence\Agents\Models\AgentDeployment;

/**
 * Discovers an agent's board cards. `list --json` rows are flat (no edges), so we `show --json <id>`
 * per kept row to populate the tree + handoff.
 *
 * Kept rows = all active cards, PLUS done cards Kanvas doesn't already track. The latter catches a
 * card created and completed entirely between sync ticks (e.g. a fast task the agent made via Slack):
 * without it that card is lost — discovery would skip it for being done, and the refresh-by-id pass
 * only re-reads cards already tracked. Once ingested it becomes tracked, so the next tick skips it
 * here — discovery stays bounded to active + newly-completed work, not all board history. Archived
 * rows are always skipped.
 */
class FetchKanbanBoardAction
{
    use InteractsWithHermesKanbanCli;

    /**
     * @param list<string> $knownTaskIds runtime task ids Kanvas already tracks (skip re-reading these once done)
     */
    public function __construct(
        private readonly AgentDeployment $deployment,
        private readonly ?string $assignee = null,
        private readonly ?string $tenant = null,
        private readonly ?string $board = null,
        private readonly array $knownTaskIds = [],
    ) {
    }

    /**
     * @return list<KanbanTask>
     */
    public function execute(): array
    {
        return $this->withCli(function (HermesContainerCliService $cli): array {
            $listArgs = ['list'];

            if ($this->assignee !== null && $this->assignee !== '') {
                $listArgs[] = '--assignee';
                $listArgs[] = $this->assignee;
            }

            if ($this->tenant !== null && $this->tenant !== '') {
                $listArgs[] = '--tenant';
                $listArgs[] = $this->tenant;
            }

            $known = array_flip($this->knownTaskIds);
            $tasks = [];

            foreach ($cli->runJson($this->kanbanArgs($listArgs)) as $row) {
                if (! is_array($row) || ! isset($row['id'])) {
                    continue;
                }

                $id = (string) $row['id'];
                $status = KanbanStatusEnum::fromRuntime(isset($row['status']) ? (string) $row['status'] : null);

                if ($status === KanbanStatusEnum::ARCHIVED) {
                    continue;
                }

                // A done card we already track is handled by the refresh-by-id pass — don't re-show it.
                if ($status === KanbanStatusEnum::DONE && isset($known[$id])) {
                    continue;
                }

                $tasks[] = KanbanTask::parseShowPayload($cli->runJson($this->kanbanArgs(['show', $id])));
            }

            return $tasks;
        });
    }
}
