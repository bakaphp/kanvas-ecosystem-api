<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Mcp\Enums;

/**
 * Credential keys are built from the `integrations` row id, never from `metadata.prefix` — the prefix
 * is cosmetic and nothing enforces its uniqueness, so two rows both set to `linear` would silently
 * share one token. The row id is unique by construction.
 */
enum ConfigurationEnum: string
{
    case TOKEN_PREFIX = 'mcp_token_';
    case OAUTH_REFRESH_PREFIX = 'mcp_refresh_token_';
    case OAUTH_EXPIRES_PREFIX = 'mcp_token_expires_at_';

    public function forIntegration(int $integrationsId): string
    {
        return $this->value . $integrationsId;
    }
}
