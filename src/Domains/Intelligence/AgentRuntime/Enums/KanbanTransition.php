<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\AgentRuntime\Enums;

/**
 * Provider-agnostic kanban lifecycle verb pushed from Kanvas → the runtime board.
 *
 * Maps to a runtime CLI/API verb (Hermes: `kanban complete|block|unblock|archive|assign`).
 * NOT a free status set — the runtime enforces a state machine, so the caller gates a verb
 * against the task's current status before issuing it (see KanbanStatusMapper::canTransition).
 * `running`/`done` flow the other way (agent-driven, observed on ingest), not pushed.
 */
enum KanbanTransition: string
{
    case COMPLETE = 'complete';
    case BLOCK = 'block';
    case UNBLOCK = 'unblock';
    case ARCHIVE = 'archive';
    case ASSIGN = 'assign';
}
