<?php

declare(strict_types=1);

namespace Kanvas\Connectors\ChromeData\Activities;

use Baka\Contracts\AppInterface;
use Illuminate\Database\Eloquent\Model;
use Kanvas\Connectors\ChromeData\Services\VehicleService;
use Kanvas\Workflow\Contracts\WorkflowActivityInterface;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\KanvasActivity;
use Override;

class AddStockImageToProductActivity extends KanvasActivity implements WorkflowActivityInterface
{
    #[Override]
    public function execute(Model $product, AppInterface $app, array $params): array
    {
        $this->overwriteAppService($app);

        return $this->executeIntegration(
            entity: $product,
            app: $app,
            integration: IntegrationsEnum::CHROMEDATA,
            additionalParams: $params,
            integrationOperation: function ($product, $app, $integrationCompany, $additionalParams) {
                // Get VIN from product variant SKU
                $variant = $product->variants()->first();
                if (! $variant) {
                    return $this->failWorkflow([
                        'success' => false,
                        'message' => 'Product does not have a variant.',
                        'product_id' => $product->getId(),
                        'product_name' => $product->name,
                    ]);
                }

                $vin = $variant->sku;
                if (empty($vin)) {
                    return $this->failWorkflow([
                        'success' => false,
                        'message' => 'Variant SKU (VIN) is empty.',
                        'product_id' => $product->getId(),
                        'product_name' => $product->name,
                        'variant_id' => $variant->getId(),
                    ]);
                }

                // Initialize ChromeData service
                $vehicleService = new VehicleService($app, $product->company);

                // Get vehicle information from ChromeData
                $vehicle = $vehicleService->getVehicleInfoByVin($vin, includeMediaGallery: false);

                if (! $vehicle || empty($vehicle->stockImage)) {
                    return $this->failWorkflow([
                        'success' => false,
                        'message' => 'No stock image found for this VIN.',
                        'product_id' => $product->getId(),
                        'product_name' => $product->name,
                        'vin' => $vin,
                    ]);
                }

                // Check existing images
                $existingFiles = $product->getFiles();
                $hasStockImage = $existingFiles->contains(function ($file) {
                    return $file->field_name === 'stock_image';
                });

                // If product has images AND has more than just the stock image, remove stock image
                if ($existingFiles->isNotEmpty() && $existingFiles->count() > 1 && $hasStockImage) {
                    $stockImageFile = $existingFiles->firstWhere('field_name', 'stock_image');
                    if ($stockImageFile) {
                        $stockImageFile->delete();

                        return [
                            'success' => true,
                            'message' => 'Stock image removed because product has other images.',
                            'product_id' => $product->getId(),
                            'product_name' => $product->name,
                            'vin' => $vin,
                            'action' => 'removed_stock_image',
                            'remaining_images_count' => $product->getFiles()->count(),
                        ];
                    }
                }

                // If product has no images, add stock image
                if ($existingFiles->isEmpty()) {
                    $product->addFileFromUrl($vehicle->stockImage, 'stock_image', $app);

                    return [
                        'success' => true,
                        'message' => 'Stock image added successfully.',
                        'product_id' => $product->getId(),
                        'product_name' => $product->name,
                        'vin' => $vin,
                        'stock_image_url' => $vehicle->stockImage,
                        'action' => 'added_stock_image',
                    ];
                }

                // Product has only the stock image or no action needed
                return [
                    'success' => true,
                    'message' => 'No action needed.',
                    'product_id' => $product->getId(),
                    'product_name' => $product->name,
                    'vin' => $vin,
                    'existing_images_count' => $existingFiles->count(),
                    'action' => 'no_action',
                ];
            },
            company: $product->company,
        );
    }
}
