<?php

declare(strict_types=1);

namespace Kanvas\Workflow\Integrations\Observers;

use Illuminate\Support\Facades\Cache;
use Kanvas\Connectors\Mcp\Services\McpToolCacheService;
use Kanvas\Companies\Models\Companies;
use Kanvas\NervousSystem\Capability\Enums\ToolTypeEnum;
use Kanvas\NervousSystem\Capability\Models\Tool;
use Kanvas\Workflow\Integrations\Models\IntegrationsCompany;
use Throwable;

/**
 * Drops the hot MCP cache when a company disconnects a server.
 *
 * Hygiene rather than correctness: the toolkit checks `isEnabled()` before it ever reads the cache, so
 * a stale entry can't resurrect a revoked capability. Clearing it just means a re-enable re-reads
 * rather than serving whatever was cached before the disconnect.
 *
 * The durable snapshot is deliberately kept — a re-enable is then instantly warm, and deactivation is
 * signalled by this row, never by the absence of a snapshot.
 */
class IntegrationsCompanyObserver
{
    public function updated(IntegrationsCompany $integrationsCompany): void
    {
        if ($integrationsCompany->wasChanged(['is_active', 'is_deleted', 'status_id'])) {
            $this->forgetMcpCache($integrationsCompany);
        }
    }

    public function deleted(IntegrationsCompany $integrationsCompany): void
    {
        $this->forgetMcpCache($integrationsCompany);
    }

    private function forgetMcpCache(IntegrationsCompany $integrationsCompany): void
    {
        try {
            $tools = Tool::query()
                ->where('tool_type', ToolTypeEnum::MCP->value)
                ->where('integrations_id', $integrationsCompany->integrations_id)
                ->get();

            if ($tools->isEmpty()) {
                return;
            }

            $company = Companies::query()->where('id', $integrationsCompany->companies_id)->first();

            if ($company === null) {
                return;
            }

            foreach ($tools as $tool) {
                $appId = $tool->apps_id > 0 ? $tool->apps_id : (int) $company->apps_id;

                Cache::forget(McpToolCacheService::cacheKeyFor(
                    $appId,
                    $company->getId(),
                    (int) $integrationsCompany->integrations_id,
                    $tool->version
                ));
            }
        } catch (Throwable) {
            // Cache hygiene must never be able to fail the write that triggered it.
        }
    }
}
