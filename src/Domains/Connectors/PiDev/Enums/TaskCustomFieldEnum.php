<?php

declare(strict_types=1);

namespace Kanvas\Connectors\PiDev\Enums;

/**
 * pi.dev linkage stored as custom fields on the Nervous System Task that represents a coding job.
 * Mirrors the Kanban sync pattern (KanbanCustomFieldEnum): the external job id + fine-grained
 * status + PR URL ride in custom fields, while the coarse lifecycle lives on the Task's own status.
 */
enum TaskCustomFieldEnum: string
{
    case PIDEV_JOB_ID = 'PIDEV_JOB_ID';
    case PIDEV_AGENT_ID = 'PIDEV_AGENT_ID';
    case PIDEV_REPO_SLUG = 'PIDEV_REPO_SLUG';
    case PIDEV_REPO_URL = 'PIDEV_REPO_URL';
    case PIDEV_STATUS = 'PIDEV_STATUS';
    case PIDEV_PULL_REQUEST_URL = 'PIDEV_PULL_REQUEST_URL';
    // Bookmark (last SSE event id) so the poller only posts NEW progress to the plan each tick.
    case PIDEV_EVENTS_CURSOR = 'PIDEV_EVENTS_CURSOR';
}
