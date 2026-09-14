<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Internal\Activities;

use Baka\Contracts\AppInterface;
use Illuminate\Database\Eloquent\Model;
use Kanvas\Workflow\Attributes\WorkflowAction;
use Kanvas\Workflow\Contracts\WorkflowActivityInterface;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\KanvasActivity;
use Override;

#[WorkflowAction(
    name: 'Unpublish Expired Product',
    description: 'Takes a single product off sale once its end-date attribute has passed. Runs on the PRODUCT '
        . 'and does nothing if the date is still in the future, so it is safe to attach to any product '
        . 'event.',
    integration: IntegrationsEnum::INTERNAL,
)]
class UnPublishExpiredProductActivity extends KanvasActivity implements WorkflowActivityInterface
{
    #[Override]
    public function execute(Model $product, AppInterface $app, array $params): array
    {
        $this->overwriteAppService($app);

        $shouldBeUnPublished = $product->attributes()->where('slug', 'end-date')->where('value', '<=', date('Y-m-d H:i:s'));

        if ($shouldBeUnPublished->count() > 0) {
            $product->unPublish();
        }

        return [
            'product' => $product->getId(),
            'status' => 'unpublished',
            'name' => $product->name,
        ];
    }
}
