<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Internal\Activities;

use Baka\Contracts\AppInterface;
use Baka\Traits\KanvasJobsTrait;
use Illuminate\Database\Eloquent\Model;
use Kanvas\Inventory\Products\Models\Products;
use Kanvas\Workflow\Contracts\WorkflowActivityInterface;
use Workflow\Activity;

class UnPublishExpiredProductActivity extends Activity implements WorkflowActivityInterface
{
    use KanvasJobsTrait;
    public $tries = 3;

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
