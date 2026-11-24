<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Setup\Enums;

enum AccountingSetupStepEnum: string
{
    case LEDGER = 'ledger';
    case FISCAL_PERIODS = 'fiscal_periods';
    case APPROVAL_POLICIES = 'approval_policies';
    case ECF_POLICIES = 'ecf_policies';
    case INBOX = 'inbox';
    case AGENT_MAILBOXES = 'agent_mailboxes';
    case DEFAULTS = 'defaults';
    case COLLECTIONS_DEFAULTS = 'collections_defaults';
    case SOW = 'sow';
}
