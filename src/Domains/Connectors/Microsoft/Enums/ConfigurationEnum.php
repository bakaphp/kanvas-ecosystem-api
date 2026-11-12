<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Microsoft\Enums;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;

enum ConfigurationEnum: string
{
    case CLIENT_ID = 'microsoft_client_id';
    case CLIENT_SECRET = 'microsoft_client_secret';
    case TENANT_ID = 'microsoft_tenant_id';
    case ACCESS_TOKEN = 'microsoft_access_token';
    case REFRESH_TOKEN = 'microsoft_refresh_token';
    case TOKEN_EXPIRES_AT = 'microsoft_token_expires_at';
    case SCOPES = 'microsoft_scopes';
    case GRANTED_SCOPES = 'microsoft_granted_scopes';
    case LAST_SYNC_AT = 'microsoft_last_sync_at';
    case ONEDRIVE_ROOT_FOLDER = 'microsoft_onedrive_root_folder';
    case OAUTH_RETURN_URL = 'microsoft_oauth_return_url';

    /**
     * A company's mail connection (tokens, sync cursor) is stored on the company once per app, as
     * "{key}-{appId}-{companyId}": one company can connect a different mailbox in each app.
     */
    public static function companySuffix(AppInterface $app, CompanyInterface $company): string
    {
        return '-' . $app->getId() . '-' . $company->getId();
    }

    /**
     * Kanvas's own App Registration, used by every app that has not configured one.
     */
    public function kanvasDefault(): ?string
    {
        return match ($this) {
            self::CLIENT_ID => config('services.microsoft.client_id'),
            self::CLIENT_SECRET => config('services.microsoft.client_secret'),
            self::TENANT_ID => config('services.microsoft.tenant_id'),
            default => null,
        };
    }
}
