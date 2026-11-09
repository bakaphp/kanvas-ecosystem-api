<?php

declare(strict_types=1);

namespace Kanvas\Connectors\ShortIo\Enums;

/**
 * Company keys first; the APP_* keys are the app-wide fallback that predates the integration and is
 * still read when a company has not connected its own short.io.
 */
enum ConfigurationEnum: string
{
    // Stored encrypted.
    case API_KEY = 'shortio_api_key';
    case DOMAIN = 'shortio_domain';
    case LINK_PAGE_BASE_URL = 'link_page_base_url';

    case APP_API_KEY = 'shortio-access-token';
    case APP_DOMAIN = 'custom-short-url';
    case APP_LINK_PAGE_BASE_URL = 'NEW_ACTION_PAGE_V4';

    public const string API_URL = 'https://api.short.io';

    /**
     * @return list<string>
     */
    public static function companyKeys(): array
    {
        return [
            self::API_KEY->value,
            self::DOMAIN->value,
            self::LINK_PAGE_BASE_URL->value,
        ];
    }
}
