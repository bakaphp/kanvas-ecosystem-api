<?php

declare(strict_types=1);

namespace Kanvas\Inventory\Products\WorkflowActivity;

use Baka\Contracts\AppInterface;
use Illuminate\Database\Eloquent\Model;
use Kanvas\Inventory\Products\Models\Products;
use Kanvas\Inventory\Variants\Actions\SyncVariantRatingAction;
use Kanvas\Workflow\Contracts\WorkflowActivityInterface;
use Kanvas\Workflow\KanvasActivity;
use Override;

class SyncProductVariantsRatingFromCategoryActivity extends KanvasActivity implements WorkflowActivityInterface
{
    #[Override]
    public function execute(Model $entity, AppInterface $app, array $params): array
    {
        $this->overwriteAppService($app);

        if (! $entity instanceof Products) {
            return [
                'status' => 'skipped',
                'reason' => 'wrong_entity_type',
                'received' => $entity::class,
            ];
        }

        if ((int) $entity->apps_id !== (int) $app->getId()) {
            return $this->failWorkflow([
                'status' => 'error',
                'message' => 'Product apps_id mismatch',
                'product_id' => $entity->getId(),
            ]);
        }

        $results = [];
        foreach ($entity->variants()->where('is_deleted', 0)->cursor() as $variant) {
            $results[] = new SyncVariantRatingAction($variant)->execute();
        }

        return [
            'status' => 'success',
            'product_id' => $entity->getId(),
            'variant_count' => count($results),
            'updated_count' => count(array_filter($results, fn ($r) => ($r['status'] ?? null) === 'updated')),
            'results' => $results,
        ];
    }
}
