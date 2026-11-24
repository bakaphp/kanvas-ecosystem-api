<?php

declare(strict_types=1);

namespace Kanvas\Connectors\WebPos\Enums;

/**
 * Company settings. The environment is not here: it is Scribe's `scribe-ecf-environment`, shared by every
 * e-CF provider, so switching provider never resets a company to production by accident.
 */
enum ConfigurationEnum: string
{
    // Stored encrypted (setEncrypted).
    case API_KEY = 'webpos_api_key';
    case COMPANY_LICENSE = 'webpos_company_lic_cod';
    case BRANCH = 'webpos_branch_cod';
    case POS = 'webpos_pos_cod';

    // Company settings are not per app; the scheduled reception pull needs to know which app owns the books.
    case APP_ID = 'webpos_app_id';

    // The OAuth login the connector used before it moved to the ApiKey routes; setup and teardown delete them.
    public const array LEGACY_LOGIN_KEYS = [
        'webpos_username',
        'webpos_password',
        'webpos_reception_username',
        'webpos_reception_password',
    ];

    /**
     * @return list<string>
     */
    public static function companyKeys(): array
    {
        return [
            ...array_map(fn (self $case): string => $case->value, self::cases()),
            ...self::LEGACY_LOGIN_KEYS,
        ];
    }
}
