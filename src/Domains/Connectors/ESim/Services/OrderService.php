<?php

declare(strict_types=1);

namespace Kanvas\Connectors\ESim\Services;

use Baka\Support\Str;
use Kanvas\Connectors\ESim\Client;
use Kanvas\Connectors\ESim\Enums\ConfigurationEnum;
use Kanvas\Connectors\ESim\Enums\ProviderEnum;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Souk\Orders\Models\OrderItem;

class OrderService
{
    protected Client $client;

    public function __construct(
        protected Order $order
    ) {
        $this->client = new Client($order->app, $order->company);
    }

    public function createOrder(): array
    {
        $item = $this->order->items()->first();
        $provider = $item->variant->product->getAttributeBySlug(ConfigurationEnum::PROVIDER_SLUG->value);

        return match (strtolower($provider->value)) {
            strtolower(ProviderEnum::E_SIM_GO->value) => $this->eSimGoOrder($item),
            strtolower(ProviderEnum::EASY_ACTIVATION->value) => $this->easyActivationOrder($item),
            default => [],
        };
    }

    protected function eSimGoOrder(OrderItem $item): array
    {
        $esimBundle = $item->variant->getAttributeByName('esim_bundle_type');
        $totalDays = $item->variant->getAttributeByName('esim_days');
        $channelId = $this->order->app->get(ConfigurationEnum::APP_CHANNEL_ID->value);

        return $this->client->post('/api/v2/esimgo/create/order', [
            'bundles' => [
                [
                    'type' => 'bundle',
                    'quantity' => $item->quantity,
                    'item' => $esimBundle->value,
                ],
            ],
            'total' => $this->order->total_net_amount,
            'total_days' => $totalDays->value,
            'wc_order_id' => 0,
            'device_id' => $channelId,
            'client' => $this->getClientDetails(),
        ]);
    }

    protected function easyActivationOrder(OrderItem $item): array
    {
        $esimDays = $item->variant->getAttributeByName('esim_days');
        $totalDays = $esimDays ? $esimDays->value : 7;
        $channelId = $this->order->app->get(ConfigurationEnum::APP_CHANNEL_ID->value);

        $metaData = $this->order->metadata;
        $startDate = $metaData['start_date'] ?? now()->format('Y-m-d');
        $endDate = $metaData['end_date'] ?? now()->addDays($totalDays)->format('Y-m-d');
        $imeiNumber = $metaData['imei_number'] ?? null;

        $this->order->user_phone ??= '1234567899';

        return $this->client->post('/api/v2/easyactivations/create/order', [
            'products' => [
                [
                    'sku' => $item->product_sku,
                    'service_days' => $totalDays,
                    'product_qty' => $item->quantity,
                    'start_date' => $startDate,
                    'imei_number' => $imeiNumber,
                ],
            ],
            'device_id' => $channelId,
            'agent_name' => $this->order->user->firstname . ' ' . $this->order->user->lastname,
            'start_date' => $startDate,
            'end_date' => $endDate,
            'total' => $this->order->total_net_amount,
            'total_days' => $totalDays,
            'language' => 'en',
            'user' => $this->getUserDetails(),
            'client' => $this->getClientDetails(),
        ]);
    }

    protected function getClientDetails(): array
    {
        return [
            'first_name' => $this->order?->people?->first_name,
            'last_name' => $this->order?->people?->last_name,
            'phone' => $this->order->user_phone,
            'email' => $this->order->user_email,
            'payment' => null,
            'imei_number' => null,
        ];
    }

    protected function getUserDetails(): array
    {
        $firstName = $this->order->user->firstname;
        $lastName = $this->order->user->lastname
                    ?? Str::of($firstName)->explode(' ')->slice(1)->join(' ')
                    ?? $firstName;

        return [
            'first_name' => $firstName,
            'last_name' => $lastName,
            'contact_number' => $this->order->user->cell_phone_number ?? $this->order->user->phone_numbers ?? $this->order->user_phone,
            'email' => $this->order->user->email,
        ];
    }
}
