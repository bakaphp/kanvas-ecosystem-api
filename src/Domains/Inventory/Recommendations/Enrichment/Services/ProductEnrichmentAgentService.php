<?php

declare(strict_types=1);

namespace Kanvas\Inventory\Recommendations\Enrichment\Services;

use Illuminate\Database\Eloquent\Builder;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Inventory\Recommendations\Enrichment\Agents\ProductEnrichmentAgent;

/**
 * Resolves the per-app enrichment Agent (the one whose AgentType points at
 * ProductEnrichmentAgent) and returns a configured, ready-to-prompt handler.
 * The Agent is only an override: an app without one enriches on the in-code
 * prompt and default vocabulary.
 */
class ProductEnrichmentAgentService
{
    /**
     * An explicit $agentId (a workflow rule or the backfill picks one) is loaded strictly within THIS
     * app, never another tenant's. Without one, the app's default enrichment agent is used, or null
     * when it has none. An explicit id that does not resolve still throws: a rule pointed at a
     * specific agent must not silently run on the defaults instead.
     */
    public static function resolveAgent(Apps $app, ?int $agentId = null): ?Agent
    {
        if ($agentId !== null) {
            /** @var Agent|null $agent */
            $agent = Agent::fromApp($app)
                ->notDeleted()
                ->where('id', $agentId)
                ->first();

            if ($agent === null) {
                throw new ValidationException(
                    "Enrichment agent {$agentId} was not found in this app."
                );
            }

            return $agent;
        }

        /** @var Agent|null $agent */
        $agent = Agent::fromApp($app)
            ->notDeleted()
            ->whereHas(
                'type',
                fn (Builder $query) => $query->where('handler', ProductEnrichmentAgent::class)
            )
            ->first();

        return $agent;
    }

    public static function handlerFor(Apps $app, ?Agent $agent, Companies $company): ProductEnrichmentAgent
    {
        $handler = new ProductEnrichmentAgent();

        if ($agent === null) {
            $handler->setConfigurationWithoutAgent($app, $company);

            return $handler;
        }

        $handler->setConfiguration(agent: $agent);

        return $handler;
    }
}
