<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Brex\Enums;

enum ConfigurationEnum: string
{
    case API_TOKEN = 'BREX_API_TOKEN';
    case SYNC_ENABLED = 'BREX_SYNC_ENABLED';
    case SYNC_APP_ID = 'BREX_SYNC_APP_ID';
    case SYNC_CURSOR = 'BREX_SYNC_CURSOR';

    /**
     * Fixed on purpose: Brex has no sandbox (`api-staging` refuses customer tokens), and a tenant-settable
     * host would turn the token-bearing client into a way to send that token anywhere.
     */
    public const string BASE_URL = 'https://api.brex.com';

    public function forAccount(string $brexAccountId): string
    {
        return $this->value . '-' . $brexAccountId;
    }
}
