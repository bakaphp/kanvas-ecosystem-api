<?php

declare(strict_types=1);

namespace Kanvas\Inventory\Products\Activities;

use Illuminate\Database\Eloquent\Model;
use Kanvas\Apps\Models\Apps;
use Kanvas\Inventory\Products\Models\Products;
use Kanvas\Inventory\Products\Services\SecondaryAlgoliaIndexService;
use Kanvas\Workflow\Attributes\WorkflowAction;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\KanvasActivity;
use RuntimeException;
use Throwable;

/**
 * `index_name` has no per-channel setting to fall back to — which channel maps to which index is a
 * decision the caller (whatever fires this activity on channel-publish) already has to make.
 *
 * `search_engine` is NOT resolved from the tenant's `products_search_engine` app setting (unlike the
 * normal single-index Scout flow); the caller states explicitly which engine the secondary index
 * lives on, since a product can be mirrored to a different backend than its primary index.
 */
#[WorkflowAction(
    description: 'Pushes a Product into a secondary search index, separate from its normal single index — e.g. a channel-specific catalog.',
    params: [
        'index_name' => 'Target index/collection name to write the product into.',
        'search_engine' => "Which search backend to write to. Defaults to 'algolia' if omitted; no other engine is implemented yet.",
    ],
    requiredParams: ['index_name'],
)]
class PushProductToSecondaryIndexActivity extends KanvasActivity
{
    public $tries = 4;

    /**
     * @param array{index_name: string, search_engine?: string} $params
     */
    public function execute(Model $entity, Apps $app, array $params): array
    {
        $this->overwriteAppService($app);

        if (! $entity instanceof Products) {
            return $this->failWorkflow([
                'result' => false,
                'message' => 'Entity is not a Product; nothing to index',
            ]);
        }

        $indexName = $params['index_name'] ?? null;
        if (! $indexName) {
            return $this->failWorkflow([
                'result' => false,
                'message' => 'index_name is required in params',
                'product_id' => $entity->id,
            ]);
        }

        $searchEngine = $params['search_engine'] ?? 'algolia';

        return $this->executeIntegration(
            entity: $entity,
            app: $app,
            integration: IntegrationsEnum::INTERNAL,
            integrationOperation: function () use ($entity, $app, $indexName, $searchEngine): array {
                try {
                    $service = match ($searchEngine) {
                        'algolia' => new SecondaryAlgoliaIndexService($app),
                        default => throw new RuntimeException("Secondary indexing for search engine '{$searchEngine}' is not implemented"),
                    };

                    if (! $entity->is_published) {
                        $service->removeProduct($entity, $indexName);

                        return [
                            'result' => true,
                            'message' => 'Product not published — removed from secondary index instead',
                            'product_id' => $entity->id,
                            'index' => $indexName,
                        ];
                    }

                    $service->indexProduct($entity, $indexName);
                } catch (Throwable $e) {
                    return [
                        'result' => false,
                        'message' => $e->getMessage(),
                        'product_id' => $entity->id,
                        'index' => $indexName,
                    ];
                }

                return [
                    'result' => true,
                    'product_id' => $entity->id,
                    'index' => $indexName,
                ];
            },
            additionalParams: $params,
            company: $entity->company,
        );
    }
}
