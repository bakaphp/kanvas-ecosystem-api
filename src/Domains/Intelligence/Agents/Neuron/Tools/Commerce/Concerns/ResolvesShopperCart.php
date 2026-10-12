<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Commerce\Concerns;

use Baka\Support\Str;
use Kanvas\Enums\AppEnums;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use Kanvas\Intelligence\Tools\Traits\ReportsToolOutcome;
use Wearepixel\Cart\Cart;

/**
 * The shopper's cart is the storefront's cart: it is keyed by the X-Kanvas-Identifier the store sends
 * with every request, which the middleware binds into the container for this request only. A chat
 * turn that arrives from the store website therefore sees the same cart the shopper sees on the page.
 * A turn from any other channel (WhatsApp, email, a queue worker) has no identifier and no cart, so
 * every cart tool refuses there instead of inventing one.
 *
 * Only a UUID counts. When the header is missing the middleware falls back to the authenticated user's
 * id, and an app-key call with no bearer token is authenticated as the app key's OWNER, so that
 * fallback would put every anonymous shopper of the store in one shared cart.
 */
trait ResolvesShopperCart
{
    use HasKanvasContext;
    use ReportsToolOutcome;

    protected function shopperCart(): ?Cart
    {
        $identifierKey = AppEnums::KANVAS_IDENTIFIER->getValue();

        if (! app()->bound($identifierKey)) {
            return null;
        }

        $identifier = (string) app($identifierKey);

        if (! Str::isUuid($identifier)) {
            return null;
        }

        return app('cart')->session($identifier);
    }

    /**
     * @return array<string, mixed>
     */
    protected function cartUnavailable(): array
    {
        return $this->denied(
            'The cart can only be changed while the shopper is chatting from the store website.',
            ['reason' => 'cart_unavailable'],
            guidance: 'Share the product link so they can add it from the product page instead.',
        );
    }

    /**
     * @return array<string, mixed>
     */
    protected function lineNotInCart(int $variantId): array
    {
        return $this->notFound(
            ['success' => false, 'message' => "Variant #{$variantId} is not in the cart."],
            guidance: 'Call view_cart to see what is in it before changing a line.',
        );
    }

    /**
     * What the shopper may see about their cart: line items, the active adjustments (discounts, fees,
     * shipping) and the totals. No internal condition attributes, no model dumps.
     *
     * @return array<string, mixed>
     */
    protected function presentCart(Cart $cart): array
    {
        $items = [];
        $currency = null;

        foreach ($cart->getContent() as $item) {
            $currency ??= $item['attributes']['currency']['code'] ?? null;

            $items[] = [
                'variant_id' => (int) $item['id'],
                'name' => $item['name'],
                'quantity' => (float) $item['quantity'],
                'unit_price' => (float) $item['price'],
                'line_total' => (float) $item->getPriceSum(),
            ];
        }

        $adjustments = [];

        foreach ($cart->getConditions() as $condition) {
            $adjustments[] = [
                'name' => $condition->getName(),
                'type' => $condition->getType(),
                'value' => $condition->getValue(),
                'amount' => (float) $cart->getCalculatedValueForCondition($condition->getName()),
            ];
        }

        return $this->ok([
            'is_empty' => $items === [],
            'currency' => $currency,
            'items' => $items,
            'adjustments' => $adjustments,
            'subtotal' => (float) $cart->getSubTotalWithoutConditions(false),
            'total' => (float) $cart->getTotal(),
        ]);
    }
}
