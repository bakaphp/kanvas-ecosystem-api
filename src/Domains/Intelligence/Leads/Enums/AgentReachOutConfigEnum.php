<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Leads\Enums;

/**
 * Per-Lead config keys + status values for the AgentReachOut* outbound-first flow.
 * Read/write keys via $lead->get($key->value) / $lead->set($key->value, ...).
 * STATUS lifecycle: null → pending → in_progress → scheduled | sent | failed | skipped | muted.
 */
enum AgentReachOutConfigEnum: string
{
    public const string STATUS_PENDING = 'pending';
    public const string STATUS_IN_PROGRESS = 'in_progress';
    public const string STATUS_SCHEDULED = 'scheduled';
    public const string STATUS_SENT = 'sent';
    public const string STATUS_FAILED = 'failed';
    public const string STATUS_SKIPPED = 'skipped';
    public const string STATUS_MUTED = 'muted';

    case STATUS = 'agent_reach_out_status';
    case SENT_AT = 'agent_reach_out_sent_at';
    case REASON = 'agent_reach_out_reason';
    case CHANNELS_SENT = 'agent_reach_out_channels_sent';
    case LAST_ERROR = 'agent_reach_out_last_error';
}
