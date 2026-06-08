<?php

declare(strict_types=1);

namespace Kanvas\Connectors\ProductEnrichment\Services;

use Illuminate\Database\Eloquent\Builder;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\ProductEnrichment\Agents\ProductEnrichmentAgent;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Intelligence\Agents\Models\Agent;

/**
 * Resolves the per-app enrichment Agent (the one whose AgentType points at
 * ProductEnrichmentAgent) and returns a configured, ready-to-prompt handler.
 */
class ProductEnrichmentAgentService
{
    /**
     * Resolve the enrichment agent for this app. When $agentId is given (the
     * workflow rule / connector config picks which agent), it is loaded strictly
     * within THIS app — never another tenant's — so each app can run its own.
     * Without one, the app's default enrichment agent is used.
     */
    public static function resolveAgent(Apps $app, ?int $agentId = null): Agent
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

        if ($agent === null) {
            throw new ValidationException(
                'No product-enrichment agent is configured for this app. Run the ProductEnrichment connector setup first.'
            );
        }

        return $agent;
    }

    public static function handlerFor(Apps $app, Agent $agent): ProductEnrichmentAgent
    {
        $handler = new ProductEnrichmentAgent();
        $handler->setConfiguration(
            agent: $agent,
            app: $app,
            company: $agent->company,
        );

        return $handler;
    }
}
