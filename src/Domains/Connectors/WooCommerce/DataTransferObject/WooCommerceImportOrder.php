<?php

declare(strict_types=1);

namespace Kanvas\Connectors\WooCommerce\DataTransferObject;

use InvalidArgumentException;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\WooCommerce\Actions\CreateProductAction;
use Kanvas\Connectors\WooCommerce\Services\WooCommerce;
use Kanvas\Currencies\Models\Currencies;
use Kanvas\Guild\Customers\Models\Address;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Inventory\Variants\Models\Variants;
use Kanvas\Regions\Models\Regions;
use Kanvas\Souk\Orders\DataTransferObject\Order as OrderDto;
use Kanvas\Users\Models\Users;

class WooCommerceImportOrder extends OrderDto
{
    public static function fromWooCommerce(
        Apps $app,
        Companies $company,
        Users $user,
        Regions $region,
        People $people,
        object $order,
        ?Address $shippingAddress = null,
        ?Address $billingAddress = null,
    ): self {
        $items = [];
        $currency = Currencies::getByCode($order->currency);

        // Calculate order-level tax total
        $taxTotal = array_reduce($order->tax_lines, function ($carry, $tax) {
            return $carry + $tax->tax_total;
        }, 0);

        foreach ($order->line_items as $item) {
            $variant = Variants::where('sku', $item->sku)
                      ->where('apps_id', $app->getId())
                      ->first();

            if (! $variant) {
                $wooCommerce = new WooCommerce($app);
                $product = $wooCommerce->client->get('products/' . $item->product_id);
                $product = (new CreateProductAction(
                    $app,
                    $company,
                    $user,
                    $region,
                    $product
                ))->execute();
                $variant = $product->variants()->where('sku', $item->sku)->first();
            }

            $items[] = [
                'app' => $app,
                'variant' => $variant,
                'name' => $item->name,
                'sku' => $item->sku,
                'quantity' => $item->quantity,
                'price' => $item->price,
                'discount' => 0,
                'tax' => (float)$item->total_tax,
                'currency' => $currency,
                'id' => $variant->id,
            ];
        }

        $shippingLine = array_reduce($order->shipping_lines, function ($carry, $shipping) {
            return $carry + $shipping->total;
        }, 0);
        $status = match ($order->status) {
            'processing' => 'draft',
            'completed' => 'completed',
            'cancelled' => 'cancelled',
            'refunded' => 'refunded',
            'failed' => 'failed',
            default => throw new InvalidArgumentException('Invalid status'),
        };

        $wooCommerceMeta = self::normalizeMetaData($order->meta_data ?? []);

        // WooCommerce ships gateway as a machine id + human title; keep the title when present.
        $paymentGateway = array_values(array_filter([
            $order->payment_method_title ?? $order->payment_method ?? null,
        ]));

        // trp_language is a locale like "es_ES"; Kanvas stores the primary subtag ("es").
        $languageCode = isset($wooCommerceMeta['trp_language'])
            ? explode('_', (string) $wooCommerceMeta['trp_language'])[0]
            : null;

        return new self(
            app: $app,
            region: $region,
            company: $company,
            people: $people,
            user: $user,
            token: $order->order_key,
            orderNumber: (string)$order->number,
            shippingAddress: $shippingAddress,
            billingAddress: $billingAddress,
            email: $order->billing->email ?? null,
            phone: $order->billing->phone ?? null,
            paymentGatewayName: $paymentGateway,
            languageCode: $languageCode,
            // Seed total is 0: the base Order DTO's getOrderItems() ADDS each line item's
            // (price x quantity) onto this value. Passing WooCommerce's order total here double-counted
            // the products (order total already includes them), inflating total_gross_amount — the
            // figure the affiliate commission is calculated off. Let the line items build the total.
            total: 0.0,
            totalDiscount: (float)$order->discount_total,
            totalShipping: $shippingLine,
            taxes: $taxTotal,
            status: $status,
            checkoutToken: '',
            currency: $currency,
            metadata: $wooCommerceMeta !== [] ? ['woocommerce_meta_data' => $wooCommerceMeta] : null,
            items: $items,
        );
    }

    /**
     * Flatten WooCommerce order meta_data into a key => value map.
     *
     * The REST API returns an array of {id, key, value} objects; some webhook payloads send an
     * already-associative object. Handle both so the caller always gets a plain associative array.
     *
     * @param iterable<mixed> $metaData
     *
     * @return array<string, mixed>
     */
    private static function normalizeMetaData(iterable $metaData): array
    {
        $normalized = [];

        foreach ($metaData as $key => $meta) {
            if (is_object($meta) && isset($meta->key)) {
                $normalized[(string) $meta->key] = $meta->value ?? null;

                continue;
            }

            if (is_array($meta) && isset($meta['key'])) {
                $normalized[(string) $meta['key']] = $meta['value'] ?? null;

                continue;
            }

            $normalized[(string) $key] = $meta;
        }

        return $normalized;
    }
}
