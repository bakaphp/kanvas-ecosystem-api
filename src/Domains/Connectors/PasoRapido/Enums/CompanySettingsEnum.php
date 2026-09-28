<?php

declare(strict_types=1);

namespace Kanvas\Connectors\PasoRapido\Enums;

/**
 * Per-company overrides stored in `companies_settings` via HashTableTrait.
 *
 * Lowercase on purpose: company settings in this codebase are snake_case
 * (`is_corporate`), unlike the UPPERCASE app-level keys in ConfigurationEnum.
 * Both are case-sensitive — HashTableTrait does no normalization.
 *
 * Every limit accepts 0, which disables that check for the company.
 */
enum CompanySettingsEnum: string
{
    case VERIFY_BLOCKED = 'paso_rapido_verify_blocked';
    case VERIFY_BLOCKED_REASON = 'paso_rapido_verify_blocked_reason';
    case VERIFY_MAX_ATTEMPTS = 'paso_rapido_verify_max_attempts';
    case VERIFY_MAX_DAILY = 'paso_rapido_verify_max_daily';
    case VERIFY_IP_MAX_DAILY = 'paso_rapido_verify_ip_max_daily';
    case VERIFY_IP_MAX_USERS = 'paso_rapido_verify_ip_max_users';
    case VERIFY_SEQUENTIAL_THRESHOLD = 'paso_rapido_verify_sequential_threshold';
}
