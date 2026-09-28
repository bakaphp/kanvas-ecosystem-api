<?php

declare(strict_types=1);

namespace Kanvas\Connectors\ClaudeAgent\Enums;

/**
 * Task-scoped linkage for an async run. There is no `claude_sessions` table — a NervousSystem Task
 * is the durable record, exactly as pi.dev uses one, so a long task shows up in the existing plan
 * board and ledger for free.
 *
 * CLAUDE_EVENT_CURSOR is what makes polling resumable: each tick drains from the last consumed
 * event instead of replaying the whole session into the plan feed.
 */
enum TaskCustomFieldEnum: string
{
    case CLAUDE_SESSION_ID = 'CLAUDE_SESSION_ID';
    case CLAUDE_EVENT_CURSOR = 'CLAUDE_EVENT_CURSOR';
    case CLAUDE_STATUS = 'CLAUDE_STATUS';
    case CLAUDE_REPO_SLUG = 'CLAUDE_REPO_SLUG';
    case CLAUDE_PULL_REQUEST_URL = 'CLAUDE_PULL_REQUEST_URL';
    case CLAUDE_STARTED_AT = 'CLAUDE_STARTED_AT';
}
