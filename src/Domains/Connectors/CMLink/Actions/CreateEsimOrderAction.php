<?php

declare(strict_types=1);

namespace Kanvas\Connectors\CMLink\Actions;

use BaconQrCode\Renderer\Image\ImagickImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Carbon\Carbon;
use Kanvas\Connectors\CMLink\Enums\ConfigurationEnum;
use Kanvas\Connectors\CMLink\Enums\PlanTypeEnum;
use Kanvas\Connectors\CMLink\Services\CustomerService;
use Kanvas\Connectors\CMLink\Services\OrderService;
use Kanvas\Connectors\ESim\DataTransferObject\ESim;
use Kanvas\Connectors\ESim\DataTransferObject\ESimStatus;
use Kanvas\Connectors\ESim\Enums\CustomFieldEnum;
use Kanvas\Connectors\ESim\Support\FileSizeConverter;
use Kanvas\Currencies\Models\Currencies;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Inventory\ProductsTypes\Models\ProductsTypes;
use Kanvas\Inventory\Variants\Models\Variants;
use Kanvas\Inventory\Variants\Repositories\VariantsRepository;
use Kanvas\Inventory\Warehouses\Models\Warehouses;
use Kanvas\Souk\Orders\DataTransferObject\OrderItem;
use Kanvas\Souk\Orders\Models\Order;

class CreateEsimOrderAction
{
    protected CustomerService $customerService;
    protected OrderService $orderService;
    protected ?Variants $availableVariant = null;
    protected Variants $orderVariant;
    protected string $variantSkuIsBundleId;
    protected ?array $esimData = null;
    protected ?array $cmLinkOrder = null;
    protected ?array $orderMetaData = null;
    protected Warehouses $warehouse;

    public function __construct(
        protected Order $order,
        ?Warehouses $warehouse = null,
        ?Variants $targetVariant = null
    ) {
        $this->warehouse = $warehouse ?? $this->order->region->defaultWarehouse;
        $this->customerService = new CustomerService($order->app, $order->company);
        $this->orderService = new OrderService($order->app, $order->company);

        // Use the provided variant or fall back to the first order item's variant
        $this->orderVariant = $targetVariant ?? $order->allItems()->first()->variant;
        $this->variantSkuIsBundleId = $this->orderVariant->getAttributeBySlug(ConfigurationEnum::PRODUCT_FATHER_SKU->value)?->value ?? $this->orderVariant->sku;
    }

    public function execute(): ESim
    {
        $this->validateOrder();

        $isRefuelOrder = isset($this->order->metadata['parent_order_id']) && ! empty($this->order->metadata['parent_order_id']);
        if ($isRefuelOrder) {
            $this->processRefuelOrder();
        } else {
            $this->processNewOrder();
        }

        $this->esimData = $this->customerService->getEsimInfo($this->availableVariant->sku);
        $qrCodeBase64 = $this->generateQrCode($this->esimData['data']['downloadUrl']);

        return $this->createESimObject($qrCodeBase64);
    }

    protected function validateOrder(): void
    {
        // Remove the validation that prevents multiple eSim creation
        // as we now want to allow multiple calls for different variants

        // Only validate if this is the first eSim creation attempt
        $orderHasMetaData = $this->order->get(CustomFieldEnum::ORDER_ESIM_METADATA->value);

        // Allow creation if no metadata exists or if we're creating additional eSims
        if (! empty($orderHasMetaData) && ! isset($orderHasMetaData['esims'])) {
            throw new ValidationException('Order already has eSim metadata');
        }
    }

    protected function processRefuelOrder(): void
    {
        $targetIccid = $this->order->metadata['target_iccid'] ?? $this->order->metadata['iccid'] ?? null;
        $findOrderByIccid = ! isset($this->order->metadata['parent_order_id']) && $targetIccid !== null;

        $parentOrder = ! $findOrderByIccid ? Order::getById($this->order->metadata['parent_order_id']) : $this->findOrderByIccid($targetIccid);

        if (! $parentOrder) {
            throw new ValidationException('Parent order not found for refuel order');
        }

        // Get the specific ICCID from the refuel order metadata
        if ($targetIccid !== null && ! empty($targetIccid)) {
            // Use the specific ICCID provided by the frontend
            $this->availableVariant = $this->findVariantByIccid($parentOrder, $targetIccid);
            if (! $this->availableVariant) {
                throw new ValidationException("Variant not found for ICCID: {$targetIccid}");
            }
        } else {
            // Fall back to original behavior if no specific ICCID provided
            $parentProductIccid = $parentOrder->allItems()->latest('id')->first();
            $this->availableVariant = $parentProductIccid->variant;
            $targetIccid = $parentProductIccid->product_sku;
        }

        $this->cmLinkOrder = $this->orderService->createOrder(
            thirdOrderId: (string) $parentOrder->order_number,
            iccid: $targetIccid,
            quantity: 1,
            activeDate: $parentOrder->created_at->format('Y-m-d'),
            dataBundleId: $this->variantSkuIsBundleId
        );

        if ($this->cmLinkOrder['code'] !== '0000000') {
            throw new ValidationException($this->cmLinkOrder['description']);
        }

        $this->orderMetaData = $parentOrder->metadata ?? [];
    }

    protected function processNewOrder(): void
    {
        // Get free iccid stock
        $this->availableVariant = $this->getAvailableVariant();
        $this->availableVariant->reduceQuantityInWarehouse($this->warehouse, 1);

        // Add this variant to the order so we have a history of the iccid
        $this->addVariantToOrder($this->availableVariant);

        $this->cmLinkOrder = $this->orderService->createOrder(
            thirdOrderId: (string) $this->order->order_number,
            iccid: $this->availableVariant->sku,
            quantity: 1,
            dataBundleId: $this->variantSkuIsBundleId,
            activeDate: $this->order->created_at->format('Y-m-d')
        );

        $this->orderMetaData = $this->order->metadata ?? [];
    }

    protected function getAvailableVariant(): Variants
    {
        // For multiple eSim creation, we need to get a fresh ICCID each time
        // Remove the logic that reuses existing variants from failed orders
        // as we want each eSim to have its own unique ICCID

        $productTypeSlug = ConfigurationEnum::ICCID_INVENTORY_PRODUCT_TYPE->value;
        $productType = ProductsTypes::fromApp($this->order->app)
            ->fromCompany($this->order->company)
            ->where('slug', $productTypeSlug)
            ->firstOrFail();

        return VariantsRepository::getAvailableVariant($productType, $this->warehouse);
    }

    protected function addVariantToOrder(Variants $variant): void
    {
        $warehouse = $this->warehouse;

        $orderItem = $this->order->addItem(new OrderItem(
            app: $this->order->app,
            variant: $variant,
            name: (string) $variant->name,
            sku: $variant->sku,
            quantity: 1,
            price: $variant->getPrice($warehouse),
            tax: 0,
            discount: 0,
            currency: Currencies::getBaseCurrency(),
        ));
        $orderItem->setPrivate();
    }

    protected function generateQrCode(string $downloadUrl): string
    {
        $writer = new Writer(
            new ImageRenderer(
                new RendererStyle(300),
                new ImagickImageBackEnd()
            )
        );

        $qrCode = $writer->writeString($downloadUrl);

        return 'data:image/png;base64,' . base64_encode($qrCode);
    }

    protected function createESimObject(string $qrCodeBase64): ESim
    {
        $totalData = $this->orderVariant->getAttributeBySlug('data')?->value ?? 0;
        $installTimeChange = isset($this->esimData['data']['installTime']) && ! empty($this->esimData['data']['installTime']) ? strtotime($this->esimData['data']['installTime']) : time();

        // Convert timestamp directly to EST
        $dateEst = Carbon::createFromTimestamp($installTimeChange)->setTimezone('America/New_York');
        // Unix timestamp in EST
        $timestampEst = $dateEst->timestamp;
        // Formatted date in EST
        $formattedEst = $dateEst->format('Y-m-d H:i:s');

        return new ESim(
            $this->esimData['data']['downloadUrl'],
            $this->availableVariant->sku,
            $this->esimData['data']['state'],
            (int) ($this->cmLinkOrder['quantity'] ?? 1),
            (float) $this->cmLinkOrder['price'],
            'bundle',
            $this->orderVariant->sku,
            $this->esimData['data']['smdpAddress'],
            $this->esimData['data']['activationCode'],
            $timestampEst,
            json_encode(['order' => $this->order->getId(), 'install_device' => $this->esimData['data']['installDevice'] ?? '']),
            $qrCodeBase64,
            new ESimStatus(
                $this->esimData['data']['activationCode'],
                'data',
                FileSizeConverter::toBytes($totalData),
                FileSizeConverter::toBytes($totalData),
                $formattedEst . ' EST' ?? $this->order->created_at->format('Y-m-d H:i:s'),
                $this->esimData['data']['activationCode'],
                $this->esimData['data']['state'],
                $this->orderVariant->getAttributeBySlug('variant-type')?->value === PlanTypeEnum::UNLIMITED,
            ),
            $this->orderMetaData['esimLabels'][0]['label'] ?? null,
        );
    }

    /**
     * Find the variant that corresponds to a specific ICCID from the parent order
     */
    protected function findVariantByIccid(Order $parentOrder, string $iccid): ?Variants
    {
        // Look through all order items to find the one with matching ICCID (SKU)
        foreach ($parentOrder->allItems()->get() as $item) {
            if ((string) $item->variant->sku === $iccid || (string) $item->product_sku === $iccid) {
                return $item->variant;
            }
        }

        return null;
    }

    protected function findOrderByIccid(string $iccid): ?Order
    {
        // Search for an order that contains the specified ICCID in its items
        return Order::query()
            ->whereHas('allItems', function ($query) use ($iccid) {
                $query->where('product_sku', $iccid);
            })
            ->first();
    }
}
