<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Approvals\Enums;

/** App-level config keys for the Slack-driven approval queue flow. */
enum ApprovalConfigurationEnum: string
{
    case SLACK_NOTIFIER_AGENT_ID = 'ap-slack-notifier-agent-id';
}
