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
    ) {
        $items = [];
        $currency = Currencies::getByCode($order->currency);
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
            $taxTotal = array_reduce($order->tax_lines, function ($carry, $tax) {
                return $carry + $tax->tax_total;
            }, 0);

            $items[] = [
                'app' => $app,
                'variant' => $variant,
                'name' => $item->name,
                'sku' => $item->sku,
                'quantity' => $item->quantity,
                'price' => $item->price,
                'discount' => 0,
                'tax' => $taxTotal,
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
            total: (float)$order->total,
            totalDiscount: (float)$order->discount_total,
            totalShipping: $shippingLine,
            taxes: $taxTotal,
            status: $status,
            checkoutToken: '',
            currency: $currency,
            items: $items,
        );
    }
}
