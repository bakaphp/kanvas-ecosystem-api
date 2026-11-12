<?php

declare(strict_types=1);

namespace Kanvas\Connectors\NetSuite\Actions;

use Baka\Support\Str;
use Baka\Users\Contracts\UserInterface;
use Illuminate\Support\Str as IlluminateStr;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\NetSuite\Concerns\FindsNetSuiteVariant;
use Kanvas\Connectors\NetSuite\Enums\CustomFieldEnum;
use Kanvas\Connectors\NetSuite\Services\NetSuiteProductService;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Inventory\Importer\Actions\ProductImporterAction;
use Kanvas\Inventory\Importer\DataTransferObjects\ProductImporter;
use Kanvas\Inventory\Regions\Repositories\RegionRepository;
use Kanvas\Inventory\Variants\Models\Variants;

/**
 * Creates the Kanvas product for a NetSuite item, keyed on the barcode (NetSuite `itemId`), so an
 * imported order or invoice can reference every line it has in NetSuite. Price, stock and attributes
 * are then filled by PullNetSuiteProductPriceAction, the same refresh the rest of the B2B catalog
 * runs, so a product created here is indistinguishable from one created by the catalog sync.
 */
class PullNetSuiteProductAction
{
    use FindsNetSuiteVariant;

    public const string SOURCE = 'netsuite';

    protected NetSuiteProductService $productService;
    protected PullNetSuiteProductPriceAction $priceRefresh;

    public function __construct(protected Apps $app, protected Companies $company, protected UserInterface $user)
    {
        $this->productService = new NetSuiteProductService($app, $company);
        $this->priceRefresh = new PullNetSuiteProductPriceAction($app, $company, $user);
    }

    public function execute(string $barcode): Variants
    {
        $existing = $this->findVariantBySku($barcode);

        if ($existing !== null) {
            return $existing;
        }

        $matches = $this->productService->searchProductByItemNumber($barcode);

        if (empty($matches)) {
            throw new ValidationException('Product ' . $barcode . ' does not exist in NetSuite');
        }

        $internalId = (string) $matches[0]->internalId;
        $item = $this->productService->getProductById($internalId);
        $name = Str::trimToNull($item->displayName) ?? Str::trimToNull($item->salesDescription) ?? $barcode;

        new ProductImporterAction(
            new ProductImporter(
                name: $name,
                slug: IlluminateStr::slug($barcode),
                sku: $barcode,
                variants: [
                    [
                        'name' => $name,
                        'sku' => $barcode,
                        'barcode' => $barcode,
                        'price' => 0,
                        'quantity' => 0,
                        'custom_fields' => [
                            ['name' => CustomFieldEnum::NET_SUITE_PRODUCT_ID->value, 'data' => $internalId],
                        ],
                    ],
                ],
                isPublished: ! ($item->isInactive ?? false),
                upc: Str::trimToNull($item->upcCode),
                source: self::SOURCE,
                sourceId: $internalId,
                warehouses: [],
            ),
            $this->company,
            $this->user,
            RegionRepository::getDefault($this->company),
            $this->app,
            runWorkflow: false,
        )->execute();

        // A failed refresh leaves price 0 in the catalog, but never the order: its lines carry NetSuite's rate.
        $this->priceRefresh->execute($barcode);

        $variant = $this->findVariantBySku($barcode);

        if ($variant === null) {
            throw new ValidationException('Product ' . $barcode . ' was pulled from NetSuite but could not be found in Kanvas');
        }

        return $variant;
    }
}
