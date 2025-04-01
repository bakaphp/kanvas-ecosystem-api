<?php

declare(strict_types=1);

namespace Kanvas\Connectors\VentaMobile\Actions;

use BaconQrCode\Renderer\Image\ImagickImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Carbon\Carbon;
use Kanvas\Connectors\ESim\DataTransferObject\ESim;
use Kanvas\Connectors\ESim\DataTransferObject\ESimStatus;
use Kanvas\Connectors\ESim\Enums\CustomFieldEnum;
use Kanvas\Connectors\ESim\Enums\PlanTypeEnum;
use Kanvas\Connectors\ESim\Support\FileSizeConverter;
use Kanvas\Connectors\VentaMobile\Enums\ConfigurationEnum;
use Kanvas\Currencies\Models\Currencies;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Inventory\ProductsTypes\Models\ProductsTypes;
use Kanvas\Inventory\Variants\Repositories\VariantsRepository;
use Kanvas\Inventory\Warehouses\Models\Warehouses;
use Kanvas\Souk\Orders\DataTransferObject\OrderItem;
use Kanvas\Souk\Orders\Models\Order;

class CreateEsimOrderAction
{
    public function __construct(
        protected Order $order,
        protected ?Warehouses $warehouse = null
    ) {
    }

    public function execute(): ESim
    {
        $orderHasMetaData = $this->order->get(CustomFieldEnum::ORDER_ESIM_METADATA->value);

        if (! empty($orderHasMetaData)) {
            throw new ValidationException('Order already has eSim metadata');
        }

        //get free iccid stock
        $productTypeSlug = ConfigurationEnum::ICCID_INVENTORY_PRODUCT_TYPE->value;
        $productType = ProductsTypes::fromApp($this->order->app)
            ->fromCompany($this->order->company)
            ->where('slug', $productTypeSlug)
            ->firstOrFail();

        $warehouse = $this->warehouse ?? $this->order->region->defaultWarehouse;

        $availableVariant = VariantsRepository::getAvailableVariant($productType, $warehouse);
        $availableVariant->reduceQuantityInWarehouse($warehouse, 1);

        /**
         * if it has a parent SKU its means its a fake product we created to sell the same product
         * at a diff price
         */
        $orderVariant = $this->order->items()->first()->variant;
        $variantSkuIsBundleId = $orderVariant->getAttributeBySlug(ConfigurationEnum::PRODUCT_FATHER_SKU->value)?->value ?? $orderVariant->sku;
        //$sku = $availableVariant->sku;

        //add this variant to the order so we have a history of the iccid
        $orderItem = $this->order->addItem(new OrderItem(
            app: $this->order->app,
            variant: $availableVariant,
            name: (string) $availableVariant->name,
            sku: $availableVariant->sku,
            quantity: 1,
            price: $availableVariant->getPrice($warehouse),
            tax: 0,
            discount: 0,
            currency: Currencies::getBaseCurrency(),
        ));
        $orderItem->setPrivate();

        /*         $orderService = new OrderService($this->order->app, $this->order->company);
                $cmLinkOrder = $orderService->createOrder(
                    thirdOrderId: (string) $this->order->order_number,
                    iccid: $availableVariant->sku,
                    quantity: 1,
                    dataBundleId: $variantSkuIsBundleId,
                    activeDate: $this->order->created_at->format('Y-m-d')
                );

                if (! isset($cmLinkOrder['quantity']) || $cmLinkOrder['quantity'] < 1) {
                    throw new ValidationException($cmLinkOrder['description']);
                }

                $customerService = new CustomerService($this->order->app, $this->order->company);
                $esimData = $customerService->getEsimInfo($availableVariant->sku); */
        $esimData = [];
        $writer = new Writer(
            new ImageRenderer(
                new RendererStyle(300),
                new ImagickImageBackEnd()
            )
        );

        $activationLink = $availableVariant->getAttributeBySlug(ConfigurationEnum::ICCID_ACTIVATION_LINK->value)?->value ?? '';

        if (empty($activationLink)) {
            throw new ValidationException('Activation link not found');
        }

        $qrCode = $writer->writeString($activationLink);
        $qrCodeBase64 = 'data:image/png;base64,' . base64_encode($qrCode);
        $orderVariant = $this->order->items()->first()->variant;
        $orderMetaData = $this->order->metadata ?? [];

        $totalData = $orderVariant->getAttributeBySlug('data')?->value ?? 0;
        $installTimeChange = ! empty($esimData['data']['installTime']) ? strtotime($esimData['data']['installTime']) : time();

        //Convert timestamp directly to EST
        $dateEst = Carbon::createFromTimestamp($installTimeChange)->setTimezone('America/New_York');
        //Unix timestamp in EST
        $timestampEst = $dateEst->timestamp;
        //Formatted date in EST
        $formattedEst = $dateEst->format('Y-m-d H:i:s');

        $activationCode = $availableVariant->getAttributeBySlug('iccid-activation-code')?->value ?? '';
        $smDpAddress = $availableVariant->getAttributeBySlug('sm-dp-address')?->value ?? '';

        $esim = new ESim(
            $activationLink,
            $availableVariant->sku,
            'Released',
            1,
            $availableVariant->getPrice($warehouse),
            'bundle',
            $orderVariant->sku,
            $smDpAddress,
            $activationCode,
            $timestampEst,
            json_encode(['order' => $this->order->getId(), 'install_device' => $esimData['data']['installDevice'] ?? '']),
            $qrCodeBase64,
            new ESimStatus(
                $activationCode,
                'data',
                FileSizeConverter::toBytes($totalData),
                FileSizeConverter::toBytes($totalData),
                $formattedEst ?? $this->order->created_at->format('Y-m-d H:i:s'),
                $activationCode,
                $esimData['data']['state'] ?? 'Released',
                $orderVariant->getAttributeBySlug('variant-type')?->value === PlanTypeEnum::UNLIMITED->value,
            ),
            $orderMetaData['esimLabels'][0]['label'] ?? null,
        );

        /* $this->order->metadata = array_merge(($this->order->metadata ?? []), $esim->toArray());
        $this->order->disableWorkflows();
        $this->order->saveOrFail(); */

        return $esim;
    }
}
