<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Enums;

enum KanvasReleaseFeedEnum: string
{
    /**
     * Comma-separated `owner/repo` list of KANVAS's own repositories. Separate from the connector's
     * generic github_token so a tenant connecting their own GitHub never widens what the customer
     * update agent is allowed to read.
     */
    case REPOSITORIES = 'kanvas_release_repositories';
}
