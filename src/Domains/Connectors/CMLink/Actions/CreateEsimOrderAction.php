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
        ?Warehouses $warehouse = null
    ) {
        $this->warehouse = $warehouse ?? $this->order->region->defaultWarehouse;
        $this->customerService = new CustomerService($order->app, $order->company);
        $this->orderService = new OrderService($order->app, $order->company);
        $this->orderVariant = $order->allItems()->first()->variant;
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
        $orderHasMetaData = $this->order->get(CustomFieldEnum::ORDER_ESIM_METADATA->value);

        if (! empty($orderHasMetaData)) {
            throw new ValidationException('Order already has eSim metadata');
        }
    }

    protected function processRefuelOrder(): void
    {
        $parentOrder = Order::getById($this->order->metadata['parent_order_id']);
        //$parentProduct = $parentOrder->allItems()->first();
        $parentProductIccid = $parentOrder->allItems()->latest('id')->first();
        $this->availableVariant = $parentProductIccid->variant;

        /* $refuelId = $this->orderVariant->getAttributeBySlug(ConfigurationEnum::PRODUCT_REFUEL_SKU->value)?->value ?? null;

        $refuelId = $refuelId[0]['refuelingID'] ?? null;

        if ($refuelId === null) {
            throw new ValidationException('Refuel ID not found for this product variant - ' . $this->orderVariant->sku);
        }
 */
        //$parentSku = $parentProduct->variant->getAttributeBySlug(ConfigurationEnum::PRODUCT_FATHER_SKU->value)?->value ?? $parentProduct->variant->sku;

        $this->cmLinkOrder = $this->orderService->createOrder(
            thirdOrderId: (string) $parentOrder->order_number,
            iccid: $parentProductIccid->product_sku,
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

        // If it has a parent SKU its means its a fake product we created to sell the same product at a diff price
        $this->orderVariant = $this->order->allItems()->first()->variant;

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
        $productTypeSlug = ConfigurationEnum::ICCID_INVENTORY_PRODUCT_TYPE->value;
        $productType = ProductsTypes::fromApp($this->order->app)
            ->fromCompany($this->order->company)
            ->where('slug', $productTypeSlug)
            ->firstOrFail();

        $warehouse = $this->warehouse;

        return VariantsRepository::getAvailableVariant($productType, $warehouse);
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
            (int) $this->cmLinkOrder['quantity'],
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
}
