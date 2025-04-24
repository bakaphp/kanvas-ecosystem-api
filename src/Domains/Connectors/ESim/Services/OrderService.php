<?php

declare(strict_types=1);

namespace Kanvas\Connectors\ESim\Services;

use Baka\Support\Str;
use Kanvas\Connectors\ESim\Client;
use Kanvas\Connectors\ESim\Enums\ConfigurationEnum;
use Kanvas\Connectors\ESim\Enums\ProviderEnum;
use Kanvas\Connectors\ESimGo\Services\EsimGoOrderService;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Souk\Orders\Models\OrderItem;

class OrderService
{
    protected Client $client;
    protected EsimGoOrderService $esimGoOrderService;

    public function __construct(
        protected Order $order
    ) {
        $this->client = new Client($order->app, $order->company);
        $this->esimGoOrderService = new EsimGoOrderService($order->app);
    }

    public function createOrder(): array
    {
        $item = $this->order->items()->first();
        //$provider = $item->variant->product->getAttributeBySlug(ConfigurationEnum::PROVIDER_SLUG->value);
        $variantProvider = $item->variant->getAttributeBySlug(ConfigurationEnum::VARIANT_PROVIDER_SLUG->value);

        // Fall back to product provider if variant provider is empty
        $provider = ! empty($variantProvider)
            ? $variantProvider
            : $item->variant->product->getAttributeBySlug(ConfigurationEnum::PROVIDER_SLUG->value);

        return match (strtolower($provider->value)) {
            strtolower(ProviderEnum::E_SIM_GO->value) => $this->eSimGoOrder($item),
            strtolower(ProviderEnum::EASY_ACTIVATION->value) => $this->easyActivationOrder($item),
            strtolower(ProviderEnum::AIRALO->value) => $this->airaloOrder($item),
            default => [],
        };
    }

    protected function eSimGoOrder(OrderItem $item): array
    {
        $isRefuelOrder = isset($this->order->metadata['parent_order_id']) && ! empty($this->order->metadata['parent_order_id']);

        if ($isRefuelOrder) {
            return $this->processEsimGoRefuelOrder($item);
        } else {
            return $this->processEsimGoNewOrder($item);
        }
    }

    protected function processEsimGoRefuelOrder(OrderItem $item): array
    {
        $esimBundle = $item->variant->getAttributeByName('esim_bundle_type');
        $iccid = $this->order->metadata['data']['iccid'] ?? null;

        if (! $iccid) {
            return [
                'status' => 'error',
                'message' => 'ICCID is required',
            ];
        }

        return $this->esimGoOrderService->rechargeOrder($iccid, $esimBundle->value);
    }

    protected function processEsimGoNewOrder(OrderItem $item): array
    {
        $esimBundle = $item->variant->getAttributeByName('esim_bundle_type');

        return $this->esimGoOrderService->makeOrder([
            [
                'type' => 'bundle',
                'quantity' => $item->quantity,
                'item' => $esimBundle->value,
            ],
        ]);
    }

    protected function easyActivationOrder(OrderItem $item): array
    {
        $esimDays = $item->variant->getAttributeByName('esim_days');
        $totalDays = $esimDays ? $esimDays->value : 7;
        $channelId = $this->order->app->get(ConfigurationEnum::APP_CHANNEL_ID->value);

        $metaData = $this->order->metadata;
        $startDate = $metaData['startDate'] ?? now()->format('Y-m-d');
        $endDate = $metaData['endDate'] ?? now()->addDays($totalDays)->format('Y-m-d');
        $imeiNumber = $metaData['deviceImei'] ?? null;

        $this->order->user_phone ??= '1234567899';

        return $this->client->post('/api/v2/easyactivations/create/order', [
            'products' => [
                [
                    'sku' => $item->variant->get('parent_sku'),
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
            'from_mobile' => 1,
            'user' => $this->getUserDetails(),
            'client' => $this->getClientDetails(),
        ]);
    }

    protected function airaloOrder(OrderItem $item): array
    {
        $esimPlan = $item->variant->getAttributeByName('esim_bundle_type');
        $esimDays = $item->variant->getAttributeByName('esim_days');
        $totalDays = $esimDays ? $esimDays->value : 7;
        $channelId = $this->order->app->get(ConfigurationEnum::APP_CHANNEL_ID->value);

        $metaData = $this->order->metadata;
        $imeiNumber = $metaData['deviceImei'] ?? null;

        // Get the agent name
        $agentName = $this->order->user->firstname . ' ' . $this->order->user->lastname;

        // Create client details with IMEI number
        $clientDetails = $this->getClientDetails();
        $clientDetails['imei_number'] = $imeiNumber;

        return $this->client->post('/api/v2/airalo/create/order', [
            'quantity' => $item->quantity,
            'plan' => $esimPlan->value,
            'type' => 'sim',
            'description' => $item->quantity . ' ' . $esimPlan->value,
            'agent_name' => $agentName,
            'device_id' => $channelId,
            'total' => (string) $this->order->total_net_amount,
            'total_days' => (string) $totalDays,
            'client' => $clientDetails,
            'from_mobile' => 1,
            'language' => 'en',
        ]);
    }

    protected function getClientDetails(): array
    {
        return [
            'first_name' => $this->order->people?->firstname,
            'last_name' => $this->order->people?->lastname,
            'phone' => $this->order->user_phone,
            'email' => $this->order->user_email,
            'payment' => null,
            'imei_number' => null,
        ];
    }

    protected function getUserDetails(): array
    {
        $firstName = $this->order->user->firstname;
        $lastName = trim((string) $this->order->user->lastname) ?:
                        Str::of($firstName)->after(' ') ?:
                        $firstName;

        return [
            'first_name' => $firstName,
            'last_name' => $lastName,
            'contact_number' => $this->order->user->cell_phone_number ?? $this->order->user->phone_numbers ?? $this->order->user_phone,
            'email' => $this->order->user->email,
        ];
    }
}
