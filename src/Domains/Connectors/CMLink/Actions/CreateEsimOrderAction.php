<?php

declare(strict_types=1);

namespace Kanvas\Connectors\CMLink\Actions;

use BaconQrCode\Renderer\Image\ImagickImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Kanvas\Connectors\CMLink\Enums\ConfigurationEnum;
use Kanvas\Connectors\CMLink\Enums\PlanTypeEnum;
use Kanvas\Connectors\CMLink\Services\CustomerService;
use Kanvas\Connectors\CMLink\Services\OrderService;
use Kanvas\Connectors\ESim\DataTransferObject\ESim;
use Kanvas\Connectors\ESim\DataTransferObject\ESimStatus;
use Kanvas\Connectors\ESim\Enums\CustomFieldEnum;
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

        //add this variant to the order so we have a history of the iccid
        $this->order->addItem(new OrderItem(
            app: $this->order->app,
            variant: $availableVariant,
            name: $availableVariant->name,
            sku: $availableVariant->sku,
            quantity: 1,
            price: $availableVariant->price,
            tax: 0,
            discount: 0,
            currency: Currencies::getDefaultCurrency(),
        ));

        $orderService = new OrderService($this->order->app, $this->order->company);
        $cmLinkOrder = $orderService->createOrder(
            (string) $this->order->order_number,
            $availableVariant->sku,
            1,
            $this->order->items()->first()->variant->sku,
            $this->order->created_at->format('Y-m-d')
        );

        $customerService = new CustomerService($this->order->app, $this->order->company);
        $esimData = $customerService->getEsimInfo($availableVariant->sku);

        $writer = new Writer(
            new ImageRenderer(
                new RendererStyle(300),
                new ImagickImageBackEnd()
            )
        );

        $qrCode = $writer->writeString($esimData['data']['downloadUrl']);
        $qrCodeBase64 = 'data:image/png;base64,' . base64_encode($qrCode);
        $orderVariant = $this->order->items()->first()->variant;

        /*
        data from cmlink
            Array
            (
                [code] => 0000000
                [description] => Success
                [orderID] => 12501130811111111s
                [totalAmount] => 3999.000000
                [quantity] => 1
                [price] => 3999.000000
                [currency] => 840
            )


            Array
            (
                [data] => Array
                    (
                        [smdpAddress] => rsp1.cmlink.com
                        [activationCode] => 975B8B4349B84F11ABE6412312312312313
                        [state] => Released
                        [eid] =>
                        [installTime] =>
                        [installDevice] =>
                        [installCount] => 0
                        [updateTime] => 2023-05-13 15:45:44
                        [downloadUrl] => LPA:1$rsp1.cmlink.com1232132131232131231231231231
                    )

                [code] => 0000000
                [msg] => Success
            )
         */
        $esim = new ESim(
            $esimData['data']['downloadUrl'],
            $availableVariant->sku,
            $esimData['data']['state'],
            (int) $cmLinkOrder['quantity'],
            (float) $cmLinkOrder['price'],
            'bundle',
            $orderVariant->sku,
            $esimData['data']['smdpAddress'],
            $esimData['data']['activationCode'],
            ! empty($esimData['data']['installTime']) ? strtotime($esimData['data']['installTime']) : time(),
            json_encode(['order' => $this->order->getId(), 'install_device' => $esimData['data']['installDevice'] ?? '']),
            $qrCodeBase64,
            new ESimStatus(
                $esimData['data']['activationCode'],
                'data',
                0,
                1000,
                $esimData['data']['installTime'] ?? $this->order->created_at->format('Y-m-d H:i:s'),
                $esimData['data']['activationCode'],
                $esimData['data']['state'],
                $orderVariant->getAttributeBySlug('variant-type')?->value === PlanTypeEnum::UNLIMITED,
            )
        );

        /* $this->order->metadata = array_merge(($this->order->metadata ?? []), $esim->toArray());
        $this->order->disableWorkflows();
        $this->order->saveOrFail(); */

        return $esim;
    }
}
