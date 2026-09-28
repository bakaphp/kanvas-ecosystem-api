<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Mcp\Enums;

/**
 * Keys are built from the `integrations` row id, never from `metadata.prefix` — the prefix is cosmetic
 * and nothing enforces its uniqueness, so two rows both set to `linear` would silently share one token.
 *
 * CREDENTIALS lives on the AGENT: one custom field per server holding the whole grant as one object —
 * `{access_token, refresh_token, expires_at, server_url}`. One per server rather than one for every
 * server, so two servers refreshing at the same moment cannot overwrite each other's tokens. The OAuth
 * client cases live on the APP, because a client identifies Kanvas to the vendor.
 */
enum ConfigurationEnum: string
{
    case CREDENTIALS_PREFIX = 'mcp_credentials_';

    case OAUTH_CLIENT_ID_PREFIX = 'mcp_oauth_client_id_';
    case OAUTH_CLIENT_SECRET_PREFIX = 'mcp_oauth_client_secret_';
    case OAUTH_REDIRECT_URI_PREFIX = 'mcp_oauth_redirect_uri_';
    case OAUTH_REGISTRATION_ENDPOINT_PREFIX = 'mcp_oauth_registration_endpoint_';

    public function forIntegration(int $integrationsId): string
    {
        return $this->value . $integrationsId;
    }

    /**
     * For a server whose address each connection supplies (a self-hosted n8n), every address is its own
     * authorization server, so its OAuth client is keyed by the address too.
     */
    public function forServer(int $integrationsId, ?string $serverUrl): string
    {
        $key = $this->forIntegration($integrationsId);

        return $serverUrl === null ? $key : $key . '_' . substr(hash('sha256', $serverUrl), 0, 16);
    }
}
