<?php

declare(strict_types=1);

namespace Baka\Search\Activities;

use Baka\Search\SecondaryAlgoliaIndexService;
use Baka\Search\SecondaryTypesenseIndexService;
use Illuminate\Database\Eloquent\Model;
use Kanvas\Apps\Models\Apps;
use Kanvas\Workflow\Attributes\WorkflowAction;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\KanvasActivity;
use RuntimeException;
use Throwable;

/**
 * Entity-agnostic: works for any model using `DynamicSearchableTrait`/Scout's `Searchable` (Products,
 * Event, Users, Leads, ...), not just Products — `toSearchableArray()` and `shouldBeSearchable()` are
 * the contract every one of those already implements, so nothing here assumes a specific entity.
 *
 * `index_name` has no per-channel setting to fall back to — which channel maps to which index is a
 * decision the caller (whatever fires this activity on channel-publish) already has to make.
 *
 * `search_engine` is NOT resolved from the tenant's `<table>_search_engine` app setting (unlike the
 * normal single-index Scout flow); the caller states explicitly which engine the secondary index
 * lives on, since an entity can be mirrored to a different backend than its primary index.
 */
#[WorkflowAction(
    description: 'Pushes any searchable entity into a secondary search index, separate from its normal single index — e.g. a channel-specific catalog.',
    params: [
        'index_name' => 'Target index/collection name to write the entity into.',
        'search_engine' => "Which search backend to write to. Defaults to 'algolia' if omitted; only 'algolia' and 'typesense' are implemented.",
    ],
    requiredParams: ['index_name'],
)]
class PushEntityToSecondaryIndexActivity extends KanvasActivity
{
    public $tries = 4;

    /**
     * @param array{index_name: string, search_engine?: string} $params
     */
    public function execute(Model $entity, Apps $app, array $params): array
    {
        $this->overwriteAppService($app);

        $indexName = $params['index_name'] ?? null;
        if (! $indexName) {
            return $this->failWorkflow([
                'result' => false,
                'message' => 'index_name is required in params',
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
                        'typesense' => new SecondaryTypesenseIndexService($app),
                        default => throw new RuntimeException("Secondary indexing for search engine '{$searchEngine}' is not implemented"),
                    };

                    if (! $entity->shouldBeSearchable()) {
                        $service->removeEntity($entity, $indexName);

                        return [
                            'result' => true,
                            'message' => 'Entity should not be searchable — removed from secondary index instead',
                            'index' => $indexName,
                        ];
                    }

                    $service->indexEntity($entity, $indexName);
                } catch (Throwable $e) {
                    return [
                        'result' => false,
                        'message' => $e->getMessage(),
                        'index' => $indexName,
                    ];
                }

                return [
                    'result' => true,
                    'index' => $indexName,
                ];
            },
            additionalParams: $params,
            company: $entity->company,
        );
    }
}
