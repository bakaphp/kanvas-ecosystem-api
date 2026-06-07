<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Hermes\Kanban\Enums;

/**
 * Hermes-specific custom-field keys. The agnostic link fields (task id, status, synced_at,
 * deployment_id, board, parent_ids) have been moved to KanbanCustomFieldEnum on the primary
 * AgentRuntime domain — the NervousSystem sync uses those directly so it never imports a
 * connector. This file retains only the Hermes-specific entry.
 */
enum CustomFieldEnum: string
{
    // On Agent: the Hermes profile name used as the kanban `assignee`.
    case HERMES_KANBAN_PROFILE = 'HERMES_KANBAN_PROFILE';
}
