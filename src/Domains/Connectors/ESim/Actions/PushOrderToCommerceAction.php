<?php

declare(strict_types=1);

namespace Kanvas\Connectors\ESim\Actions;

use Baka\Support\Str;
use Illuminate\Support\Facades\Http;
use Kanvas\Connectors\CMLink\Enums\ConfigurationEnum as CMLinkEnumsConfigurationEnum;
use Kanvas\Connectors\ESim\DataTransferObject\ESim;
use Kanvas\Connectors\ESim\Enums\ConfigurationEnum;
use Kanvas\Connectors\WooCommerce\Enums\ConfigurationEnum as EnumsConfigurationEnum;
use Kanvas\Souk\Orders\Models\Order;

class PushOrderToCommerceAction
{
    public function __construct(
        protected Order $order,
        protected ESim $esim
    ) {
    }

    public function execute(string $provider): array
    {
        $esimData = $this->order->metadata['data'] ?? [];
        $firstItem = $this->order->items()->first();
        $variant = $firstItem->variant;
        $destination = $variant->product->getAttributeBySlug('destination')?->value ?? '';
        $descriptionName = $variant->product->getAttributeBySlug('destination-name')?->value ?? '';
        $commerceSku = $variant->product->getAttributeBySlug('commerce-sku')?->value ?? 'esim-eu';
        $commerceProductId = $variant->product->getAttributeBySlug('commerce-product-id')?->value ?? '20';
        $variantDuration = $variant->getAttributeBySlug('variant-duration')?->value ?? null;
        $sku = $variant->getAttributeBySlug(CMLinkEnumsConfigurationEnum::PRODUCT_FATHER_SKU->value)?->value ?? $variant->sku;

        $response = Http::withHeaders([
            'X-API-Key' => $this->order->app->get(ConfigurationEnum::COMMERCE_API_KEY->value),
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ])->post($this->order->app->get(EnumsConfigurationEnum::WORDPRESS_URL->value) . '/wp-json/esim/v1/create-stand-order', [
            'provider' => $provider,
            'api' => $provider,
            'coverage' => strtolower($variant->product->productType->name),
            'destination_code' => $destination,
            'sku' => $sku, //$commerceSku,
            'from_kanvas' => true,
            'iccid' => $this->esim->iccid,
            'apn' => null,
            'client_payment' => null,
            'device_location' => 'mobile',
            'device_code' => 'MOBILE',
            'order_reference' => Str::uuid()->toString(),
            'lpa_code' => $this->esim->lpaCode,
            'matching_id' => $this->esim->matchingId,
            'smdp_address' => $this->esim->smdpAddress,
            'phone_number' => null,
            'partner_id' => 6,
            'product_id' => $commerceProductId,
            'product_name' => $firstItem->product_name,
            'language' => 'es_ES',
            'destination' => $descriptionName,
            'client_imei' => null,
            'qr_code' => $this->esim->qrCode,
            //'client_imei' => $esim->esimStatus->imei ?? 'USER_SKIPPED',
            'client_email' => $this->order->user_email,
            'start_date' => null,
            'end_date' => null,
            'client_name' => $this->order->people->firstname . ' ' . $this->order->people->lastname,
            'esim_status' => 'completed',
            'order_source' => 'mobile-app',
            'total_days' => $variantDuration,
            'first_name' => $this->order->people->firstname,
            'last_name' => $this->order->people->lastname,
            'client_phone' => $this->order->user_phone,
            'agent_name' => null,
            'is_unlimited' => (int) ($this->esim->esimStatus->unlimited ?? false),
            'total' => $this->order->total_net_amount,
        ]);

        return $response->json();
    }
}
