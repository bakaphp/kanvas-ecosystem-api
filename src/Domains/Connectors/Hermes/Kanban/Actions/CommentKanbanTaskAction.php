<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Hermes\Kanban\Actions;

use Kanvas\Connectors\Hermes\Kanban\Traits\InteractsWithHermesKanbanCli;
use Kanvas\Connectors\Hermes\Services\HermesContainerCliService;
use Kanvas\Intelligence\Agents\Models\AgentDeployment;

/**
 * Posts a comment on a Hermes card: `kanban comment <id> "<text>" --author <author>`.
 *
 * The verb returns plain text ("Comment added to t_xxx"), not JSON — fire-and-forget, no re-read.
 * The worker replays the whole thread on its next spawn, so a comment is how a human steers it.
 * `author` carries provenance (`kanvas:<uid>`) so the ingest skips comments we wrote ourselves.
 */
class CommentKanbanTaskAction
{
    use InteractsWithHermesKanbanCli;

    public function __construct(
        private readonly AgentDeployment $deployment,
        private readonly string $externalTaskId,
        private readonly string $text,
        private readonly string $author,
        private readonly ?string $board = null,
    ) {
    }

    public function execute(): void
    {
        $this->withCli(function (HermesContainerCliService $cli): void {
            $cli->run($this->kanbanArgs([
                'comment',
                $this->externalTaskId,
                $this->text,
                '--author',
                $this->author,
            ]));
        });
    }
}
