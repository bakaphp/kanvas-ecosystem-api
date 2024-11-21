<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Internal\Activities;

use Baka\Contracts\AppInterface;
use Illuminate\Database\Eloquent\Model;
use Kanvas\Inventory\Products\Models\Products;
use Kanvas\Workflow\Contracts\WorkflowActivityInterface;
use Kanvas\Workflow\KanvasActivity;

class UnPublishExpiredProductActivity extends KanvasActivity implements WorkflowActivityInterface
{
    /**
     * @param Products $product
     */
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
