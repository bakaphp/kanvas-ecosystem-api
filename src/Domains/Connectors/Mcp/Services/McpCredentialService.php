<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Mcp\Services;

use Baka\Support\Str;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Mcp\DataTransferObject\McpServerConfig;
use Kanvas\Connectors\Mcp\Enums\ConfigurationEnum;
use Kanvas\Workflow\Models\Integrations;
use Throwable;

/**
 * Reads and writes the per-company credential for one MCP server.
 *
 * `$app` is passed rather than derived from `$integration->app`: curated MCP rows live at `apps_id=0`
 * and are shared by every app, so the model's own app relation is not the acting tenant.
 *
 * The token lands in company settings the way every other connector stores one (Calendly, ChromeData,
 * ClaudeAgent). Those are plaintext key/value via Baka's HashTableTrait — MCP does not lower that bar,
 * but platform-wide credential encryption is its own ticket.
 */
class McpCredentialService
{
    public function __construct(
        private readonly Apps $app,
        private readonly Companies $company,
        private readonly Integrations $integration,
    ) {
    }

    public function token(): ?string
    {
        $config = McpServerConfig::fromIntegration($this->integration);

        if ($config->auth->needsRefresh()) {
            return new McpOAuthService($this->app, $this->company, $this->integration)->accessToken();
        }

        return $this->rawToken();
    }

    /**
     * The stored value with no refresh logic — the OAuth path calls this for the token it may then
     * decide to refresh.
     */
    public function rawToken(): ?string
    {
        try {
            $token = $this->company->get(
                ConfigurationEnum::TOKEN_PREFIX->forIntegration($this->integration->getId())
            );
        } catch (Throwable) {
            return null;
        }

        return Str::trimToNull(is_string($token) ? $token : null);
    }

    public function storeToken(string $token): void
    {
        $this->company->set(
            ConfigurationEnum::TOKEN_PREFIX->forIntegration($this->integration->getId()),
            $token
        );
    }

    public function forget(): void
    {
        $this->company->set(
            ConfigurationEnum::TOKEN_PREFIX->forIntegration($this->integration->getId()),
            ''
        );
    }
}
