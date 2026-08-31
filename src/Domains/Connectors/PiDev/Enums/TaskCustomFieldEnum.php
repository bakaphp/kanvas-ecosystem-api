<?php

declare(strict_types=1);

namespace Kanvas\Connectors\PiDev\Enums;

enum TaskCustomFieldEnum: string
{
    case PIDEV_JOB_ID = 'PIDEV_JOB_ID';
    case PIDEV_AGENT_ID = 'PIDEV_AGENT_ID';
    case PIDEV_REPO_SLUG = 'PIDEV_REPO_SLUG';
    case PIDEV_REPO_URL = 'PIDEV_REPO_URL';
    case PIDEV_STATUS = 'PIDEV_STATUS';
    case PIDEV_PULL_REQUEST_URL = 'PIDEV_PULL_REQUEST_URL';
    case PIDEV_EVENTS_CURSOR = 'PIDEV_EVENTS_CURSOR';
    case PIDEV_AUTO_RETRY_COUNT = 'PIDEV_AUTO_RETRY_COUNT';
}
