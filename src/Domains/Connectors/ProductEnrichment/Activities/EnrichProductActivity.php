<?php

declare(strict_types=1);

namespace Kanvas\Connectors\ProductEnrichment\Activities;

use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\ProductEnrichment\Actions\EnrichProductAction;
use Kanvas\Inventory\Products\Models\Products;
use Kanvas\Workflow\Attributes\WorkflowAction;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\KanvasActivity;

/**
 * Wire this to Products `created` + `updated`. Without a rule, a catalogue is only ever enriched by
 * the one-off backfill command and silently goes stale as products are added or edited.
 */
#[WorkflowAction(
    name: 'Enrich Product For Search',
    description: 'Writes the search profile a product needs to be findable by natural-language '
        . 'questions ("algo elegante para mi mama", "a luxury SUV for a big family"). An LLM reads the '
        . 'product name, description, categories and attributes and produces audience/occasion/interest '
        . 'facets, vocabulary tags, and a short bilingual blurb that becomes the text semantic search '
        . 'matches against. Run it on product created and updated: a product with no blurb falls back '
        . 'to keyword matching on its name. Re-running is cheap — it fingerprints the fields the prompt '
        . 'uses and skips the LLM entirely when nothing that matters has changed, so price and stock '
        . 'edits cost nothing. '
        . 'REQUIRES an Agent in this app whose type is "Product Enrichment" (provider: laravel) — '
        . 'without one the step fails with "No product-enrichment agent is configured for this app". '
        . 'Create it first, and leave the agent\'s instructions field EMPTY so it keeps the shipped '
        . 'prompt; anything written there replaces the prompt entirely. '
        . 'The agent needs a model that supports structured JSON output. Gemini works for THIS step '
        . 'because it runs without tools, but it rejects structured output combined with function '
        . 'calling — so if you ever give the enrichment agent tools, or you are configuring the '
        . 'Inventory Recommendation agent (which has tools by design), use OpenAI or Anthropic '
        . 'instead.',
    integration: IntegrationsEnum::INTERNAL,
    params: [
        'agent_id' => 'Which enrichment agent to use, loaded strictly within this app. Omit it to use '
            . 'the app default agent (the one whose type is Product Enrichment). Set it only when an '
            . 'app runs several with different prompts or vocabularies.',
    ],
)]
class EnrichProductActivity extends KanvasActivity
{
    public function execute(Products $entity, Apps $app, array $params): array
    {
        $this->overwriteAppService($app);

        return $this->executeIntegration(
            entity: $entity,
            app: $app,
            integration: IntegrationsEnum::INTERNAL,
            integrationOperation: fn () => new EnrichProductAction(
                $entity,
                isset($params['agent_id']) ? (int) $params['agent_id'] : null,
            )->execute(),
            additionalParams: $params,
            company: $entity->company,
        );
    }
}
