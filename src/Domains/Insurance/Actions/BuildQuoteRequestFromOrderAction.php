<?php

declare(strict_types=1);

namespace Kanvas\Insurance\Actions;

use Kanvas\Exceptions\ValidationException;
use Kanvas\Insurance\DataTransferObject\InsuranceQuoteRequest;
use Kanvas\Inventory\Variants\Models\Variants;
use Kanvas\Souk\Orders\Models\Order;

class BuildQuoteRequestFromOrderAction
{
    private const VEHICLE_PRODUCT_TYPE = 'vehicle';

    public function __construct(
        protected Order $order,
    ) {
    }

    public function execute(): InsuranceQuoteRequest
    {
        $metadata = (array) ($this->order->metadata['insurance'] ?? []);
        $product = (string) ($metadata['product'] ?? '');

        if ($product === '') {
            throw new ValidationException('Order carries no insurance product to quote.');
        }

        $payload = (array) ($metadata['payload'] ?? []);

        if (! empty($metadata['cliente'])) {
            $payload['cliente'] = (array) $metadata['cliente'];
        }

        return new InsuranceQuoteRequest(
            product: $product,
            payload: $payload,
            vehicle: $this->vehicle(),
        );
    }

    protected function vehicle(): ?Variants
    {
        $typed = $this->order->items->first(
            fn ($item): bool => $item->variant?->product?->productsType?->slug === self::VEHICLE_PRODUCT_TYPE
        );

        return $typed?->variant ?? $this->order->items->first()?->variant;
    }
}
