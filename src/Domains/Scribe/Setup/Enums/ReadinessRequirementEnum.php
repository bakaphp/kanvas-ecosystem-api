<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Setup\Enums;

/**
 * What an AP/AR agent needs to work. The values are also the `readiness` keys onboarding setup steps
 * point at, so a step closes when its requirement turns ready.
 */
enum ReadinessRequirementEnum: string
{
    case LEDGER = 'ledger';
    case FISCAL_PERIODS = 'fiscal_periods';
    case INBOX = 'inbox';
    case APPROVER = 'approver';
    case BANK = 'bank';
    case ERP = 'erp';
    case ECF_PROVIDER = 'ecf_provider';
    case ECF_RANGES = 'ecf_ranges';
    case SLACK = 'slack';

    public function label(): string
    {
        return match ($this) {
            self::LEDGER => 'Ledger',
            self::FISCAL_PERIODS => 'Fiscal periods',
            self::INBOX => 'Accounting inbox',
            self::APPROVER => 'Approver',
            self::BANK => 'Bank feed',
            self::ERP => 'Accounting software',
            self::ECF_PROVIDER => 'Electronic invoicing (e-CF)',
            self::ECF_RANGES => 'e-NCF ranges',
            self::SLACK => 'Slack',
        };
    }
}
