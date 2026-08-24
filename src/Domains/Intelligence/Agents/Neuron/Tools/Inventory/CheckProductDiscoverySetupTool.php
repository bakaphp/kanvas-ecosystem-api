<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Inventory;

use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use Kanvas\Inventory\Recommendations\Services\ProductDiscoveryStatusService;
use NeuronAI\Tools\Tool;
use Override;
use Throwable;

/**
 * Read-only, so a PM agent can answer "is discovery ready?" before touching
 * anything. Every failed check carries its own fix — setup is ordered and the
 * order is not guessable, so a list of gaps without remedies is not much help.
 */
#[AgentTool(name: 'Check Product Discovery Setup', category: 'inventory')]
class CheckProductDiscoverySetupTool extends Tool
{
    use HasKanvasContext;

    public function __construct()
    {
        parent::__construct(
            name: 'check_product_discovery_setup',
            description: 'Report whether natural-language product discovery is fully configured for this '
                . 'company, and what is missing. Checks the search engine, Typesense credentials and '
                . 'collection, whether the collection declares the embedding and audience fields, the '
                . 'enrichment agent, how many products actually have a search blurb, and whether a '
                . 'workflow rule keeps them fresh. Returns a fix for every failed check. Run this BEFORE '
                . 'configuring anything and again afterwards — several steps only work in the right order, '
                . 'so a partial setup looks fine and quietly falls back to keyword matching. It is also '
                . 'the first thing to run when discovery returns NOTHING: a filter on a field the '
                . 'collection does not declare matches zero products rather than being ignored, and that '
                . 'reads as an empty catalog.',
        );
    }

    /**
     * @return array<int, object>
     */
    #[Override]
    protected function properties(): array
    {
        return [];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(): array
    {
        if (! $this->hasTenantContext()) {
            return [
                'status' => 'error',
                'message' => 'This agent has no company context, so it cannot check discovery setup.',
            ];
        }

        try {
            $report = new ProductDiscoveryStatusService($this->app, $this->company)->report();
        } catch (Throwable $e) {
            report($e);

            return [
                'status' => 'error',
                'message' => 'Could not read the discovery setup: ' . $e->getMessage(),
            ];
        }

        $blocking = array_values(array_filter($report['checks'], static fn (array $c): bool => ! $c['ok']));

        return [
            'status' => 'success',
            'ready' => $report['ready'],
            'checks' => $report['checks'],
            'note' => $report['ready']
                ? 'Discovery is fully configured for this company.'
                : count($blocking) . ' step(s) still missing. Apply the fixes in the order listed — the '
                    . 'embedding field can only be added when the collection is created, and enrichment '
                    . 'must run before indexing is worth anything.',
        ];
    }
}
