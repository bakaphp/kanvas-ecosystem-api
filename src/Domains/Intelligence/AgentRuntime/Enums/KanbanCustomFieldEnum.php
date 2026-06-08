<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\AgentRuntime\Enums;

/**
 * Agnostic custom-field keys linking a Kanvas Plan/Task to a runtime kanban task.
 * Lives on AgentRuntime so the NervousSystem sync never imports a connector.
 */
enum KanbanCustomFieldEnum: string
{
    /**
     * Provenance marker prefixed to the `--author` of comments Kanvas pushes to a card, so the
     * ingest can recognize and skip its own writes (no echo). Format: `kanvas:<users_id>`.
     */
    public const string KANVAS_AUTHOR_PREFIX = 'kanvas:';

    case TASK_ID = 'AGENT_KANBAN_TASK_ID';
    case STATUS = 'AGENT_KANBAN_STATUS';
    case SYNCED_AT = 'AGENT_KANBAN_SYNCED_AT';
    case DEPLOYMENT_ID = 'AGENT_KANBAN_DEPLOYMENT_ID';
    case BOARD = 'AGENT_KANBAN_BOARD';
    case PARENT_IDS = 'AGENT_KANBAN_PARENT_IDS';
    case LAST_COMMENT_AT = 'AGENT_KANBAN_LAST_COMMENT_AT';
}
