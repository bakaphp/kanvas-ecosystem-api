<?php

declare(strict_types=1);

namespace App\GraphQL\NervousSystem\Mutations;

use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Mcp\Services\McpToolCacheService;
use Kanvas\Exceptions\ValidationException;
use Kanvas\NervousSystem\Capability\Models\Tool;
use Kanvas\Users\Models\Users;
use Kanvas\Workflow\Models\Integrations;
use Throwable;

class McpMutation
{
    /**
     * Force a re-read of a server's tool list.
     *
     * The descriptor cache is long-lived on purpose — a stale entry is recoverable, and churn throws
     * away the provider's prompt cache — so an admin who has just changed something on the vendor side
     * needs a way to say "look again now" rather than waiting out the TTL.
     */
    public function refreshTools(mixed $rootValue, array $request): bool
    {
        $app = app(Apps::class);
        /** @var Users $user */
        $user = auth()->user();
        $company = Companies::getById($user->getCurrentCompany()->getId());

        $tool = Tool::query()
            ->where('id', (int) $request['tool_id'])
            ->fromAppOrGlobal($app)
            ->first();

        if ($tool === null || ! $tool->isMcp()) {
            throw new ValidationException(sprintf('Tool #%d is not an MCP server.', (int) $request['tool_id']));
        }

        $integration = $tool->integration;

        if (! $integration instanceof Integrations) {
            throw new ValidationException('This MCP tool is not linked to an integration.');
        }

        $cache = new McpToolCacheService(
            app: $app,
            company: $company,
            integration: $integration,
            toolVersion: $tool->version,
        );

        if (! $cache->connection()->isEnabled()) {
            throw new ValidationException('This company has not connected that MCP server.');
        }

        try {
            $cache->refresh();
        } catch (Throwable $e) {
            throw new ValidationException('Could not refresh the tool list: ' . $e->getMessage());
        }

        return true;
    }
}
