<?php

declare(strict_types=1);

namespace Kanvas\Connectors\VentaMobile\Actions;

use BaconQrCode\Renderer\Image\ImagickImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Carbon\Carbon;
use Exception;
use Kanvas\Connectors\ESim\DataTransferObject\ESim;
use Kanvas\Connectors\ESim\DataTransferObject\ESimStatus;
use Kanvas\Connectors\ESim\Enums\AttributeEnum;
use Kanvas\Connectors\ESim\Enums\CustomFieldEnum as ESimCustomFieldEnum;
use Kanvas\Connectors\ESim\Support\FileSizeConverter;
use Kanvas\Connectors\VentaMobile\Enums\ConfigurationEnum;
use Kanvas\Connectors\VentaMobile\Enums\PlanTypeEnum;
use Kanvas\Connectors\VentaMobile\Services\ESimService;
use Kanvas\Connectors\VentaMobile\Services\SubscriberService;
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
    protected ESimService $eSimService;
    protected SubscriberService $subscriberService;
    protected ?Variants $availableVariant = null;
    protected Variants $orderVariant;
    protected int $extensionId;
    protected ?array $esimData = null;
    protected ?array $orderMetaData = null;
    protected Warehouses $warehouse;
    protected string|int|null $iccid = null;
    protected string|int|null $msisdn = null;
    protected string|int|null $imsi = null;
    protected string|int|null $serviceId = null;
    protected string|int|null $contractId = null;
    protected ?array $activationResult = null;
    protected ?array $balanceInfo = null;
    protected ?string $lpaCode = null;

    public function __construct(
        protected Order $order,
        ?Warehouses $warehouse = null
    ) {
        $this->warehouse = $warehouse ?? $this->order->region->defaultWarehouse;
        $this->eSimService = new ESimService($order->app, $order->company);
        $this->subscriberService = new SubscriberService($order->app, $order->company);
        $this->orderVariant = $order->allItems()->first()->variant;

        // Get the extension ID from the variant attributes
        $this->extensionId = (int) ($this->orderVariant->getAttributeBySlug(ConfigurationEnum::PRODUCT_FATHER_SKU->value)?->value ?? $this->orderVariant->sku);
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

        // Generate QR code (placeholder implementation since VentaMobile doesn't provide QR URLs)
        $qrCodeBase64 = $this->generateQrCode($this->lpaCode);

        return $this->createESimObject($qrCodeBase64);
    }

    protected function validateOrder(): void
    {
        $orderHasMetaData = $this->order->get(ESimCustomFieldEnum::ORDER_ESIM_METADATA->value);

        if (! empty($orderHasMetaData)) {
            throw new ValidationException('Order already has eSim metadata');
        }
    }

    protected function processRefuelOrder(): void
    {
        // Get parent order information
        $parentOrder = Order::getById($this->order->metadata['parent_order_id']);
        $parentProductIccid = $parentOrder->allItems()->latest('id')->first();
        $this->availableVariant = $parentProductIccid->variant;
        $this->iccid = $this->availableVariant->sku;

        try {
            // Get service information
            $serviceInfo = $this->eSimService->getServiceByIccid($this->iccid);

            if (empty($serviceInfo)) {
                throw new ValidationException("No service found for ICCID: {$this->iccid}");
            }

            $service = $serviceInfo[0];
            $this->serviceId = $service['services_info']['id_service_inst'];
            $this->contractId = $service['id_contract_inst'];

            // Check if we need to customize the data amount and validity period
            $dataAmount = FileSizeConverter::toBytes($this->orderVariant->getAttributeBySlug('data')?->value);
            $validityDays = $this->orderVariant->getAttributeBySlug('esim_days')?->value;
            $periodType = $this->orderVariant->getAttributeBySlug('period-type')?->value ?? 0;

            if ($dataAmount > 0 && $validityDays !== null) {
                // Activate with custom values
                $this->activationResult = $this->eSimService->activateExtensionWithCustomValues(
                    $this->serviceId,
                    $this->extensionId,
                    $dataAmount,
                    (int) $periodType,
                    (int) $validityDays
                );
            } else {
                // Activate with default values
                $this->activationResult = $this->eSimService->activateExtension(
                    (int) $this->serviceId,
                    $this->extensionId
                );
            }

            // Get balance information
            $this->balanceInfo = $this->eSimService->getServiceBalance($this->serviceId);

            $this->orderMetaData = $parentOrder->metadata ?? [];
        } catch (Exception $e) {
            throw new ValidationException('Failed to process refuel order: ' . $e->getMessage());
        }
    }

    protected function processNewOrder(): void
    {
        // Get available ICCID variant
        $this->availableVariant = $this->getAvailableVariant();
        $this->availableVariant->reduceQuantityInWarehouse($this->warehouse, 1);
        $this->iccid = $this->availableVariant->sku;
        $this->imsi = $this->availableVariant->getAttributeBySlug('imsi')?->value;
        $this->lpaCode = $this->availableVariant->getAttributeBySlug('lpa')?->value;

        // Add this variant to the order so we have a history of the ICCID
        $this->addVariantToOrder($this->availableVariant);

        // Check if service already exists for this ICCID
        $serviceInfo = $this->eSimService->getServiceByIccid($this->iccid);

        if (empty($serviceInfo)) {
            // Need to create a new service

            // Get an available phone number
            $availableNumbers = $this->subscriberService->getAvailablePhoneNumbers('free', null, 1);
            if (empty($availableNumbers)) {
                throw new ValidationException('No available phone numbers found');
            }
            $this->msisdn = $availableNumbers[0];

            // Get offer ID from variant attributes
            $offerId = $this->orderVariant->getAttributeBySlug('offer_id')?->value ?? 3640; // Default if not specified

            // Create subscriber
            $subscriberResult = $this->subscriberService->createCompleteSubscriber(
                (int) $offerId,
                (string) $this->imsi,
                (string) $this->msisdn
            );

            if (! isset($subscriberResult['id_service_inst'])) {
                throw new ValidationException('Failed to create subscriber: ' . json_encode($subscriberResult));
            }

            $this->serviceId = $subscriberResult['id_service_inst'];
            $this->contractId = $subscriberResult['id_contract_inst'];
        } else {
            // Service already exists
            $service = $serviceInfo[0];
            $this->serviceId = $service['services_info']['id_service_inst'];
            $this->contractId = $service['id_contract_inst'];
            $this->msisdn = $service['services_info']['msisdn'] ?? null;
            $this->imsi = $service['services_info']['imsi'] ?? null;
        }

        // Check if we need to customize the data amount and validity period
        $dataAmount = FileSizeConverter::toBytes($this->orderVariant->getAttributeBySlug('data')?->value);
        $validityDays = $this->orderVariant->getAttributeBySlug('esim_days')?->value;
        $periodType = $this->orderVariant->getAttributeBySlug('period-type')?->value ?? 0;

        if ($dataAmount > 0 && $validityDays !== null) {
            // Activate with custom values
            $this->activationResult = $this->eSimService->activateExtensionWithCustomValues(
                $this->serviceId,
                $this->extensionId,
                $dataAmount,
                (int) $periodType,
                (int) $validityDays
            );
        } else {
            // Activate with default values
            $this->activationResult = $this->eSimService->activateExtension(
                (int) $this->serviceId,
                $this->extensionId
            );
        }

        // Get balance information
        $this->balanceInfo = $this->eSimService->getServiceBalance($this->serviceId);

        $this->orderMetaData = $this->order->metadata ?? [];
    }

    protected function getAvailableVariant(): Variants
    {
        // Check for previously assigned ICCID in failed orders
        if ($this->order->allItems()->count() === 2) {
            $lastVariant = $this->order->allItems()->latest()->first()->variant;

            if ($lastVariant->product->productType->slug === ConfigurationEnum::ICCID_INVENTORY_PRODUCT_TYPE->value) {
                return $lastVariant;
            }
        }

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

    protected function generateQrCode(string $content): string
    {
        $writer = new Writer(
            new ImageRenderer(
                new RendererStyle(300),
                new ImagickImageBackEnd()
            )
        );

        $qrCode = $writer->writeString($content);

        return 'data:image/png;base64,' . base64_encode($qrCode);
    }

    protected function createESimObject(string $qrCodeBase64): ESim
    {
        // Get data amount from balance info or variant attribute
        $dataAmount = 0;
        $dataRemaining = 0;
        if (! empty($this->balanceInfo)) {
            // Find the right balance entry for our extension
            foreach ($this->balanceInfo as $balance) {
                if (isset($balance['incomes']) && is_array($balance['incomes'])) {
                    foreach ($balance['incomes'] as $income) {
                        if (isset($income['id_extension']) && $income['id_extension'] == $this->extensionId) {
                            $dataAmount = $income['issue'] ?? 0;
                            $dataRemaining = $income['value'] ?? 0;

                            break 2;
                        }
                    }
                }
            }
        }

        if ($dataAmount == 0) {
            $dataAmount = $this->orderVariant->getAttributeBySlug('data_amount_bytes')?->value ?? 1073741824; // Default to 1GB
        }

        // Get validity days from balance info or variant attribute
        $validityDays = 0;
        $validityTimestamp = time();
        if (! empty($this->balanceInfo)) {
            // Find the right balance entry for our extension
            foreach ($this->balanceInfo as $balance) {
                if (isset($balance['incomes']) && is_array($balance['incomes'])) {
                    foreach ($balance['incomes'] as $income) {
                        if (isset($income['dt_issue'])) {
                            $validityTimestamp = $income['dt_issue'];
                        }
                        if (isset($income['validdays']) && isset($income['validdays']['count'])) {
                            $validityDays = $income['validdays']['count'];

                            break 2;
                        }
                    }
                }
            }
        }

        if ($validityDays == 0) {
            $validityDays = $this->orderVariant->getAttributeBySlug('validity_days')?->value ?? 7; // Default to 7 days
        }

        // Convert timestamp to EST
        $dateEst = Carbon::createFromTimestamp($validityTimestamp)->setTimezone('America/New_York');
        // Unix timestamp in EST
        $timestampEst = $dateEst->timestamp;
        // Formatted date in EST
        $formattedEst = $dateEst->format('Y-m-d H:i:s');

        return new ESim(
            $this->lpaCode,
            $this->iccid,
            'ACTIVE', // State - assuming active after successful activation
            1, // Quantity
            (float) ($this->order->allItems()->first()->price ?? 0),
            'bundle',
            (string) $this->extensionId,
            $this->availableVariant->getAttributeBySlug(AttributeEnum::SMDP_ADDRESS->value)?->value,
            $this->msisdn, // Use MSISDN as activation code
            $timestampEst,
            (string) json_encode([
                'order' => $this->order->getId(),
                'service_id' => $this->serviceId,
                'contract_id' => $this->contractId,
                'msisdn' => $this->msisdn,
                'imsi' => $this->imsi,
            ]),
            $qrCodeBase64,
            new ESimStatus(
                $this->msisdn, // Activation code
                'data',
                (int) $dataAmount,
                (int) ($dataRemaining ?: $dataAmount),
                $formattedEst . ' EST',
                $this->iccid, // Confirmation code
                'ACTIVE',
                $this->orderVariant->getAttributeBySlug('variant-type')?->value === PlanTypeEnum::UNLIMITED,
            ),
            $this->orderMetaData['esimLabels'][0]['label'] ?? null,
        );
    }
}
