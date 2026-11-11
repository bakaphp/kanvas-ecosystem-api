<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Setup\Enums;

/**
 * Company settings written by the accounting setup.
 */
enum AccountingSetupConfigurationEnum: string
{
    /** Per-step state of the last run: {ran_at, steps: {<step>: {status, at, detail}}}. */
    case SETUP_STATE = 'scribe-accounting-setup';

    /** The person who approves bills and invoices when the vendor or customer names nobody. */
    case DEFAULT_APPROVER = 'scribe-default-approver-users-id';

    /**
     * Inbox bills nothing else could code get an AI-suggested expense account, its confidence shown to
     * the approver. Switched on by the setup; off for companies that never ran it.
     */
    case AI_EXPENSE_CODING = 'scribe-ai-expense-coding';
}
