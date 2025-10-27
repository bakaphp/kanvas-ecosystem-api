<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Recombee\Workflows;

use Baka\Contracts\AppInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Kanvas\Connectors\Recombee\Services\RecombeeProductIndexService;
use Kanvas\Workflow\Contracts\WorkflowActivityInterface;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\Enums\StatusEnum;
use Kanvas\Workflow\KanvasActivity;
use Override;
use Throwable;

class PushProductToItemActivity extends KanvasActivity implements WorkflowActivityInterface
{
    public $tries = 4;

    /**
     * @param \Kanvas\Inventory\Products\Models\Products $product
     */
    #[Override]
    public function execute(Model $product, AppInterface $app, array $params): array
    {
        $this->overwriteAppService($app);

        try {
            $company = $app->getAppCompany();
        } catch (ModelNotFoundException $e) {
            $company = $product->company;
        }

        return $this->executeIntegration(
            entity: $product,
            app: $app,
            integration: IntegrationsEnum::RECOMBEE,
            integrationOperation: function ($product, $app, $integrationCompany, $additionalParams) use ($params) {
                $productTypeId = $params['product_type_id'] ?? null;

                if (! $product->is_published) {
                    return $this->failWorkflow([
                        'result' => false,
                        'message' => 'Product is not published, should not be indexed',
                        'id' => $product->id,
                    ]);
                }

                if ($productTypeId !== null) {
                    if ($product->products_types_id !== (int) $productTypeId) {
                        $this->setWorkflowStatus(StatusEnum::FAILED);

                        return $this->failWorkflow([
                            'result' => false,
                            'message' => 'Product type does not match the expected ' . (string) $productTypeId . ' but found ' . (string) $product->products_types_id,
                            'id' => $product->id,
                        ]);
                    }
                }

                try {
                    $productIndex = new RecombeeProductIndexService($app);
                    $productIndex->createProductCatalogDatabase();

                    $result = $productIndex->indexProduct($product);
                } catch (Throwable $e) {
                    return [
                        'result' => false,
                        'message' => $e->getMessage(),
                        'id' => $product->id,
                    ];
                }

                return [
                    'result' => $result,
                    'product_id' => $product->id,
                    'slug' => $product->slug ?? $product->uuid,
                ];
            },
            company: $company
        );
    }
}
