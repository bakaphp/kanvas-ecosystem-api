<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\AgentRuntime\Enums;

/**
 * Agnostic custom-field keys linking a Kanvas Plan/Task to a runtime kanban task.
 * Lives on AgentRuntime so the NervousSystem sync never imports a connector.
 */
enum KanbanCustomFieldEnum: string
{
    case TASK_ID = 'AGENT_KANBAN_TASK_ID';
    case STATUS = 'AGENT_KANBAN_STATUS';
    case SYNCED_AT = 'AGENT_KANBAN_SYNCED_AT';
    case DEPLOYMENT_ID = 'AGENT_KANBAN_DEPLOYMENT_ID';
    case BOARD = 'AGENT_KANBAN_BOARD';
    case PARENT_IDS = 'AGENT_KANBAN_PARENT_IDS';
}
