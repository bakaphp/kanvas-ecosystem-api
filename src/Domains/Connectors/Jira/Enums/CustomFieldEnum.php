<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Jira\Enums;

/**
 * Entity-level linkage back to Jira, mirroring `Trello\Enums\CustomFieldEnum` — set on whatever
 * Kanvas entity a rule filed the issue for, so a re-run updates the existing issue instead of
 * filing a duplicate.
 */
enum CustomFieldEnum: string
{
    case JIRA_ISSUE_KEY = 'JIRA_ISSUE_KEY';
    case JIRA_ISSUE_ID = 'JIRA_ISSUE_ID';
}
