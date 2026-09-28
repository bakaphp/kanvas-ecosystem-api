<?php

declare(strict_types=1);

namespace Kanvas\Connectors\PiDev\Enums;

/**
 * Agent-scoped custom fields. The GitHub token and repo allow-list live on the Agent (not the
 * company) so each agent carries a least-privilege PAT scoped to only its own repos.
 */
enum CustomFieldEnum: string
{
    case PIDEV_GITHUB_TOKEN = 'PIDEV_GITHUB_TOKEN';
    case PIDEV_ALLOWED_REPOS = 'PIDEV_ALLOWED_REPOS';
    case PIDEV_SYSTEM_PROMPT = 'PIDEV_SYSTEM_PROMPT';
}
