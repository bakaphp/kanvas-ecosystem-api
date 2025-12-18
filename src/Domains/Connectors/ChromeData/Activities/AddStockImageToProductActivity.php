<?php

declare(strict_types=1);

namespace Kanvas\Connectors\ChromeData\Activities;

use Baka\Contracts\AppInterface;
use Illuminate\Database\Eloquent\Model;
use Kanvas\Connectors\ChromeData\Enums\ConfigurationEnum;
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

        if (empty($product->company->get(ConfigurationEnum::ACCOUNT_NUMBER->value))) {
            return [];
        }

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

                // Get make/model/year from product attributes for better caching
                $make = $product->getAttributeBySlug('make')?->value;
                $model = $product->getAttributeBySlug('model')?->value;
                $year = $product->getAttributeBySlug('year')?->value;

                // Handle array values
                if (is_array($make)) {
                    $make = $make[0] ?? null;
                }
                if (is_array($model)) {
                    $model = $model[0] ?? null;
                }
                if (is_array($year)) {
                    $year = $year[0] ?? null;
                }

                // Check existing images first to avoid unnecessary API calls
                $existingFiles = $product->getFiles();
                $hasStockImage = $existingFiles->contains(function ($file) {
                    return $file->field_name === 'stock_image';
                });

                // If product already has 1 image and it's a stock image, skip to avoid using credits
                if ($existingFiles->count() === 1 && $hasStockImage) {
                    return [
                        'success' => true,
                        'message' => 'Product already has stock image. Skipping to avoid using credits.',
                        'product_id' => $product->getId(),
                        'product_name' => $product->name,
                        'vin' => $vin,
                        'action' => 'skipped',
                    ];
                }

                // Initialize ChromeData service
                $vehicleService = new VehicleService($app, $product->company);

                // Get stock image using new optimized method
                $stockImage = $vehicleService->getStockImageByVin($vin, $make, $model, (int) $year);

                if (empty($stockImage)) {
                    return $this->failWorkflow([
                        'success' => false,
                        'message' => 'No stock image found for this VIN.',
                        'product_id' => $product->getId(),
                        'product_name' => $product->name,
                        'vin' => $vin,
                        'make' => $make,
                        'model' => $model,
                        'year' => $year,
                    ]);
                }

                $hasStockImage = $existingFiles->contains(function ($file) {
                    return $file->field_name === 'stock_image';
                });

                // If product has images AND has more than just the stock image, remove stock image from both product and variant
                if ($existingFiles->isNotEmpty() && $existingFiles->count() > 1 && $hasStockImage) {
                    $stockImageFile = $existingFiles->firstWhere('field_name', 'stock_image');
                    if ($stockImageFile) {
                        $stockImageFile->delete();

                        // Also remove stock image from variant
                        $variantStockImage = $variant->getFiles()->firstWhere('field_name', 'stock_image');
                        if ($variantStockImage) {
                            $variantStockImage->delete();
                        }

                        return [
                            'success' => true,
                            'message' => 'Stock image removed from product and variant because product has other images.',
                            'product_id' => $product->getId(),
                            'product_name' => $product->name,
                            'variant_id' => $variant->getId(),
                            'vin' => $vin,
                            'action' => 'removed_stock_image',
                            'remaining_images_count' => $product->getFiles()->count(),
                        ];
                    }
                }

                // If product has no images, add stock image to both product and variant
                if ($existingFiles->isEmpty()) {
                    $product->addFileFromUrl($stockImage, 'stock_image', $app);
                    $variant->addFileFromUrl($stockImage, 'stock_image', $app);

                    return [
                        'success' => true,
                        'message' => 'Stock image added successfully to product and variant.',
                        'product_id' => $product->getId(),
                        'product_name' => $product->name,
                        'variant_id' => $variant->getId(),
                        'vin' => $vin,
                        'make' => $make,
                        'model' => $model,
                        'year' => $year,
                        'stock_image_url' => $stockImage,
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
