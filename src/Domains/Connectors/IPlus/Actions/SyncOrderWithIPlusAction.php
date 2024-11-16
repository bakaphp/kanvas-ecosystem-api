<?php

declare(strict_types=1);

namespace Kanvas\Connectors\IPlus\Actions;

use Kanvas\Connectors\IPlus\Client;
use Kanvas\Connectors\IPlus\Enums\ConfigurationEnum;
use Kanvas\Connectors\IPlus\Enums\CustomFieldEnum;
use Kanvas\Connectors\Shopify\Enums\CustomFieldEnum as EnumsCustomFieldEnum;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Souk\Orders\Models\Order;

class SyncOrderWithIPlusAction
{
    protected Client $client;

    public function __construct(
        protected Order $order
    ) {
        if (! $this->order->company->get(ConfigurationEnum::COMPANY_ID->value)) {
            throw new ValidationException('IPlus company ID is not set for ' . $this->order->company->name);
        }

        $this->client = new Client($this->order->app, $this->order->company);
    }

    public function execute(): string
    {
        if ($this->order->get(CustomFieldEnum::I_PLUS_ORDER_ID->value)) {
            return $this->order->get(CustomFieldEnum::I_PLUS_ORDER_ID->value);
        }

        $orderData = [
            'clienteID' => $this->order->people->get(CustomFieldEnum::I_PLUS_CUSTOMER_ID->value),
            'clienteNombre' => $this->order->people->firstname . ' ' . $this->order->people->lastname,
            'referencia' => $this->order->order_number,
            'totalBruto' => $this->order->total_gross_amount,
            'totalNeto' => $this->order->total_net_amount,
            'comentario' => $this->order->get(EnumsCustomFieldEnum::SHOPIFY_ORDER_ID->value),
        ];

        $createOrder = $this->client->post(
            '/v2/Ventas/Ordenes',
            $orderData
        );

        return $createOrder['orderID'];
    }
}
