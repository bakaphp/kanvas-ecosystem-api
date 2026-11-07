<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Microsoft\Enums;

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
}
