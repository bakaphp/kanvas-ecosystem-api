<?php

declare(strict_types=1);

namespace Kanvas\Connectors\ESim\Services;

use Baka\Support\Str;
use Kanvas\Connectors\ESim\Client;
use Kanvas\Connectors\ESim\Enums\ConfigurationEnum;
use Kanvas\Connectors\ESim\Enums\ProviderEnum;
use Kanvas\Inventory\Variants\Models\Variants;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Souk\Orders\Models\OrderItem;

class OrderService
{
    protected Client $client;
    protected ?Variants $targetVariant = null;

    public function __construct(
        protected Order $order,
        ?Variants $targetVariant = null
    ) {
        $this->client = new Client($order->app, $order->company);
        $this->targetVariant = $targetVariant;
    }

    public function createOrder(): array
    {
        // Use the provided variant or fall back to the first order item's variant
        if ($this->targetVariant) {
            // Create a temporary OrderItem-like object for the target variant
            $item = $this->createOrderItemFromVariant($this->targetVariant);
            $variantProvider = $this->targetVariant->getAttributeBySlug(ConfigurationEnum::VARIANT_PROVIDER_SLUG->value);
            $provider = ! empty($variantProvider)
                ? $variantProvider
                : $this->targetVariant->product->getAttributeBySlug(ConfigurationEnum::PROVIDER_SLUG->value);
        } else {
            // Original behavior - use first order item
            $item = $this->order->items()->first();
            $variantProvider = $item->variant->getAttributeBySlug(ConfigurationEnum::VARIANT_PROVIDER_SLUG->value);
            $provider = ! empty($variantProvider)
                ? $variantProvider
                : $item->variant->product->getAttributeBySlug(ConfigurationEnum::PROVIDER_SLUG->value);
        }

        return match (strtolower($provider->value)) {
            strtolower(ProviderEnum::E_SIM_GO->value) => $this->eSimGoOrder($item),
            strtolower(ProviderEnum::EASY_ACTIVATION->value) => $this->easyActivationOrder($item),
            strtolower(ProviderEnum::AIRALO->value) => $this->airaloOrder($item),
            default => [],
        };
    }

    /**
     * Create a temporary OrderItem-like object from a variant for processing
     */
    protected function createOrderItemFromVariant(Variants $variant): object
    {
        return (object) [
            'variant' => $variant,
            'quantity' => 1, // Default quantity for individual eSim creation
        ];
    }

    protected function eSimGoOrder($item): array
    {
        $isRefuelOrder = isset($this->order->metadata['parent_order_id']) && ! empty($this->order->metadata['parent_order_id']);

        if ($isRefuelOrder) {
            return $this->processEsimGoRefuelOrder($item);
        } else {
            return $this->processEsimGoNewOrder($item);
        }
    }

    protected function processEsimGoRefuelOrder($item): array
    {
        $esimBundle = $item->variant->getAttributeByName('esim_bundle_type');

        // Get the specific ICCID from the refuel order metadata
        $targetIccid = $this->order->metadata['target_iccid'] ?? $this->order->metadata['iccid'] ?? null;

        // Fall back to legacy metadata location if not found
        if (! $targetIccid) {
            $targetIccid = $this->order->metadata['data']['iccid'] ?? null;
        }

        if (! $targetIccid) {
            return [
                'status' => 'error',
                'message' => 'ICCID is required for refuel order',
            ];
        }

        return $this->client->post('/api/v1/esimgo/recharge', [
            'iccid' => $targetIccid,
            'name' => $esimBundle->value,
        ]);
    }

    protected function processEsimGoNewOrder($item): array
    {
        $esimBundle = $item->variant->getAttributeByName('esim_bundle_type');
        $totalDays = $item->variant->getAttributeByName('esim_days');
        $channelId = $this->order->app->get(ConfigurationEnum::APP_CHANNEL_ID->value);

        // For multiple eSim creation, use quantity of 1 per call
        $quantity = $this->targetVariant ? 1 : $item->quantity;

        return $this->client->post('/api/v2/esimgo/create/order', [
            'bundles' => [
                [
                    'type' => 'bundle',
                    'quantity' => $quantity,
                    'item' => $esimBundle->value,
                ],
            ],
            'total' => $this->order->total_net_amount,
            'total_days' => $totalDays->value,
            'wc_order_id' => 0,
            'device_id' => $channelId,
            'from_mobile' => 1,
            'client' => $this->getClientDetails(),
        ]);
    }

    protected function easyActivationOrder($item): array
    {
        $isRefuelOrder = isset($this->order->metadata['parent_order_id']) && ! empty($this->order->metadata['parent_order_id']);

        if ($isRefuelOrder) {
            return $this->processEasyActivationRefuelOrder($item);
        } else {
            return $this->processEasyActivationNewOrder($item);
        }
    }

    protected function processEasyActivationRefuelOrder($item): array
    {
        // Get the specific ICCID from the refuel order metadata
        $targetIccid = $this->order->metadata['target_iccid'] ?? $this->order->metadata['iccid'] ?? null;

        if (! $targetIccid) {
            return [
                'status' => 'error',
                'message' => 'ICCID is required for refuel order',
            ];
        }

        $esimDays = $item->variant->getAttributeByName('esim_days');
        $totalDays = $esimDays ? $esimDays->value : 7;
        $channelId = $this->order->app->get(ConfigurationEnum::APP_CHANNEL_ID->value);

        $metaData = $this->order->metadata;
        $startDate = $metaData['startDate'] ?? now()->format('Y-m-d');
        $endDate = $metaData['endDate'] ?? now()->addDays($totalDays)->format('Y-m-d');
        $imeiNumber = $metaData['deviceImei'] ?? null;

        $this->order->user_phone ??= '1234567899';

        return $this->client->post('/api/v2/easyactivations/refuel/order', [
            'iccid' => $targetIccid,
            'sku' => $item->variant->get('parent_sku'),
            'service_days' => $totalDays,
            'start_date' => $startDate,
            'end_date' => $endDate,
            'imei_number' => $imeiNumber,
            'device_id' => $channelId,
            'agent_name' => $this->order->user->firstname . ' ' . $this->order->user->lastname,
            'total' => $this->order->total_net_amount,
            'total_days' => $totalDays,
            'language' => 'en',
            'from_mobile' => 1,
            'user' => $this->getUserDetails(),
            'client' => $this->getClientDetails(),
        ]);
    }

    protected function processEasyActivationNewOrder($item): array
    {
        $esimDays = $item->variant->getAttributeByName('esim_days');
        $totalDays = $esimDays ? $esimDays->value : 7;
        $channelId = $this->order->app->get(ConfigurationEnum::APP_CHANNEL_ID->value);

        $metaData = $this->order->metadata;
        $startDate = $metaData['startDate'] ?? now()->format('Y-m-d');
        $endDate = $metaData['endDate'] ?? now()->addDays($totalDays)->format('Y-m-d');
        $imeiNumber = $metaData['deviceImei'] ?? null;

        $this->order->user_phone ??= '1234567899';

        // For multiple eSim creation, use quantity of 1 per call
        $quantity = $this->targetVariant ? 1 : $item->quantity;

        return $this->client->post('/api/v2/easyactivations/create/order', [
            'products' => [
                [
                    'sku' => $item->variant->get('parent_sku'),
                    'service_days' => $totalDays,
                    'product_qty' => $quantity,
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

    protected function airaloOrder($item): array
    {
        $isRefuelOrder = isset($this->order->metadata['parent_order_id']) && ! empty($this->order->metadata['parent_order_id']);

        if ($isRefuelOrder) {
            return $this->processAiraloRefuelOrder($item);
        } else {
            return $this->processAiraloNewOrder($item);
        }
    }

    protected function processAiraloRefuelOrder($item): array
    {
        // Get the specific ICCID from the refuel order metadata
        $targetIccid = $this->order->metadata['target_iccid'] ?? $this->order->metadata['iccid'] ?? null;

        if (! $targetIccid) {
            return [
                'status' => 'error',
                'message' => 'ICCID is required for refuel order',
            ];
        }

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

        return $this->client->post('/api/v1/airalo/recharge', [
            'iccid' => $targetIccid,
            'package_id' => $esimPlan->value,
            'plan' => $esimPlan->value,
            'type' => 'sim',
            'description' => '1 ' . $esimPlan->value . ' refuel',
            'agent_name' => $agentName,
            'device_id' => $channelId,
            'total' => (string) $this->order->total_net_amount,
            'total_days' => (string) $totalDays,
            'client' => $clientDetails,
            'from_mobile' => 1,
            'language' => 'en',
        ]);
    }

    protected function processAiraloNewOrder($item): array
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

        // For multiple eSim creation, use quantity of 1 per call
        $quantity = $this->targetVariant ? 1 : (int) $item->quantity;

        return $this->client->post('/api/v2/airalo/create/order', [
            'quantity' => $quantity,
            'plan' => $esimPlan->value,
            'type' => 'sim',
            'description' => $quantity . ' ' . $esimPlan->value,
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
