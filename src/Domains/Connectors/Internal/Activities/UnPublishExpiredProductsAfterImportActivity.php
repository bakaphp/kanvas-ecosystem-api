<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Internal\Activities;

use Baka\Contracts\AppInterface;
use Illuminate\Database\Eloquent\Model;
use Kanvas\Inventory\Products\Models\Products;
use Kanvas\Workflow\Attributes\WorkflowAction;
use Kanvas\Workflow\Contracts\WorkflowActivityInterface;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\KanvasActivity;
use Override;

#[WorkflowAction(
    name: 'Unpublish Expired Products After Import',
    description: 'Sweeps the whole company\'s catalog and takes every past-end-date product off sale. The bulk '
        . 'counterpart of the single-product step — it runs on the COMPANY, which is what makes it '
        . 'right after an import and wrong as a per-product step.',
    integration: IntegrationsEnum::INTERNAL,
)]
class UnPublishExpiredProductsAfterImportActivity extends KanvasActivity implements WorkflowActivityInterface
{
    /**
     */
    #[Override]
    public function execute(Model $company, AppInterface $app, array $params): array
    {
        $this->overwriteAppService($app);

        if (! isset($params['process_product_ids'])) {
            return [
                'status' => 'missing process_product_ids',
                'company_name' => $company->name,
            ];
        }

        $productsId = $params['process_product_ids'];

        // Retrieve products with expired end-dates
        $shouldBeUnPublished = Products::whereIn('id', $productsId)
            ->whereHas('attributes', function ($query) {
                $query->where('slug', 'end-date')
                    ->where('value', '<=', now());
            })
        ->get();

        // Unpublish each expired product
        foreach ($shouldBeUnPublished as $product) {
            $product->unPublish();
        }

        return [
            'products' => $shouldBeUnPublished->pluck('id')->all(),
            'status' => 'unpublished',
            'company_name' => $company->name,
        ];
    }
}
