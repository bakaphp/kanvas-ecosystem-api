<?php

declare(strict_types=1);

namespace Kanvas\Inventory\Variants\WorkflowActivity;

use Baka\Contracts\AppInterface;
use Illuminate\Database\Eloquent\Model;
use Kanvas\Inventory\Variants\Actions\SetVariantSortingRatingFromCategoryAction;
use Kanvas\Inventory\Variants\Models\Variants;
use Kanvas\Workflow\Attributes\WorkflowAction;
use Kanvas\Workflow\Contracts\WorkflowActivityInterface;
use Kanvas\Workflow\KanvasActivity;
use Override;

#[WorkflowAction]
class SetVariantSortingRatingFromCategoryActivity extends KanvasActivity implements WorkflowActivityInterface
{
    #[Override]
    public function execute(Model $entity, AppInterface $app, array $params): array
    {
        $this->overwriteAppService($app);

        if (! $entity instanceof Variants) {
            return [
                'status' => 'skipped',
                'reason' => 'wrong_entity_type',
                'received' => $entity::class,
            ];
        }

        if ((int) $entity->apps_id !== (int) $app->getId()) {
            return $this->failWorkflow([
                'status' => 'error',
                'message' => 'Variant apps_id mismatch',
                'variant_id' => $entity->getId(),
            ]);
        }

        return new SetVariantSortingRatingFromCategoryAction($entity)->execute();
    }
}
