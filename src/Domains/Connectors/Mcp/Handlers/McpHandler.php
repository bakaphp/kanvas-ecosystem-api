<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Mcp\Handlers;

use Kanvas\Connectors\Contracts\BaseIntegration;
use Kanvas\Connectors\Mcp\Exceptions\McpAuthException;
use Kanvas\Connectors\Mcp\Services\McpConnectionService;
use Kanvas\Connectors\Mcp\Services\McpCredentialService;
use Kanvas\Connectors\Mcp\Services\McpToolCacheService;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Workflow\Models\Integrations;
use Override;
use Throwable;

/**
 * One handler for every MCP server in the catalog — the row it is setting up arrives as
 * `$this->integration`, which is why `BaseIntegration` gained that parameter.
 *
 * Proving the credential means doing the real thing: `initialize` + `tools/list` against the actual
 * server. That doubles as the first snapshot write, so the company's very first agent turn is already
 * warm instead of paying three round trips.
 */
class McpHandler extends BaseIntegration
{
    #[Override]
    public function setup(): bool
    {
        $integration = $this->integration;

        if ($integration === null) {
            throw new ValidationException('MCP setup requires the integration row it belongs to.');
        }

        $token = trim((string) ($this->data['token'] ?? ''));

        if ($token === '') {
            throw new ValidationException('An MCP access token is required.');
        }

        $credentials = new McpCredentialService($this->app, $this->company, $integration);

        // The transport reads the credential from company settings at send time, so it has to be on
        // file before the probe runs — the same shape Apollo uses. It is removed again if the server
        // rejects it, so a failed setup leaves nothing behind.
        $credentials->storeToken($token);

        try {
            $descriptors = $this->connectionFor($integration)->fetchDescriptors();
        } catch (McpAuthException $e) {
            $credentials->forget();

            throw new ValidationException('The MCP server rejected that token: ' . $e->getMessage());
        } catch (Throwable $e) {
            $credentials->forget();

            throw new ValidationException('Could not reach the MCP server: ' . $e->getMessage());
        }

        $this->warmSnapshot($integration, $descriptors);

        return true;
    }

    /** Overridden in tests to swap in a fake transport; production always uses the guarded one. */
    protected function connectionFor(Integrations $integration): McpConnectionService
    {
        return new McpConnectionService($this->app, $this->company, $integration);
    }

    /**
     * @param list<array<string, mixed>> $descriptors
     */
    protected function warmSnapshot(Integrations $integration, array $descriptors): void
    {
        try {
            new McpToolCacheService($this->app, $this->company, $integration)->store($descriptors);
        } catch (Throwable) {
            // A cold cache is a slow first turn, not a failed connection — the credential is proven
            // either way, and refusing the setup over a cache write would be the wrong trade.
        }
    }
}
