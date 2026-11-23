<?php

declare(strict_types=1);

namespace Kanvas\Connectors\NetSuite\Actions;

use Baka\Support\Str;
use Baka\Users\Contracts\UserInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str as IlluminateStr;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\NetSuite\Concerns\FindsNetSuiteVariant;
use Kanvas\Connectors\NetSuite\Enums\CustomFieldEnum;
use Kanvas\Connectors\NetSuite\Services\NetSuiteProductService;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Inventory\Importer\Actions\ProductImporterAction;
use Kanvas\Inventory\Importer\DataTransferObjects\ProductImporter;
use Kanvas\Inventory\Products\Models\Products;
use Kanvas\Inventory\Regions\Repositories\RegionRepository;
use Kanvas\Inventory\Variants\Models\Variants;
use NetSuite\Classes\InventoryItem;

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
            return $existing->product !== null ? $existing : $this->restore($existing, $barcode);
        }

        [$internalId, $item] = $this->netSuiteItem($barcode);
        $name = $this->itemName($item, $barcode);

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

    /**
     * A live variant whose product was soft-deleted. Pulling a new product would collide on the SKU
     * and orphan the variant past orders point at, so the product comes back, refreshed from NetSuite.
     */
    protected function restore(Variants $variant, string $barcode): Variants
    {
        $product = Products::withTrashed()
            ->fromApp($this->app)
            ->fromCompany($this->company)
            ->where('id', $variant->products_id)
            ->first();

        if ($product === null) {
            throw new ValidationException('Product ' . $barcode . ' has a variant in Kanvas whose product no longer exists');
        }

        [$internalId, $item] = $this->netSuiteItem($barcode);
        $name = $this->itemName($item, $barcode);

        DB::connection('inventory')->transaction(function () use (
            $product,
            $variant,
            $item,
            $name,
            $barcode
        ): void {
            $product->restore();
            $product->name = $name;
            $product->is_published = ! ($item->isInactive ?? false);
            $product->saveOrFail();

            $variant->name = $name;
            $variant->barcode = Str::trimToNull($variant->barcode) ?? $barcode;
            $variant->saveOrFail();
        });

        $variant->set(CustomFieldEnum::NET_SUITE_PRODUCT_ID->value, $internalId);
        $this->priceRefresh->execute($barcode);

        return $variant->refresh();
    }

    /**
     * @return array{0: string, 1: InventoryItem}
     */
    protected function netSuiteItem(string $barcode): array
    {
        $matches = $this->productService->searchProductByItemNumber($barcode);

        if (empty($matches)) {
            throw new ValidationException('Product ' . $barcode . ' does not exist in NetSuite');
        }

        $internalId = (string) $matches[0]->internalId;

        return [$internalId, $this->productService->getProductById($internalId)];
    }

    protected function itemName(InventoryItem $item, string $barcode): string
    {
        return Str::trimToNull($item->displayName) ?? Str::trimToNull($item->salesDescription) ?? $barcode;
    }
}
