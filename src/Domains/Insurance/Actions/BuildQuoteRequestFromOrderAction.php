<?php

declare(strict_types=1);

namespace Kanvas\Insurance\Actions;

use Kanvas\Exceptions\ValidationException;
use Kanvas\Insurance\DataTransferObject\InsuranceQuoteRequest;
use Kanvas\Souk\Orders\Models\Order;

/**
 * Rebuilds the request the customer compared, from the order created when they
 * picked it, so a re-quote never depends on the client resending the payload.
 * The insurer-shaped body is stored under `metadata.insurance.payload` at
 * contract time and the customer under `metadata.insurance.cliente`, which is
 * laid over the payload; the vehicle is whatever variant the order is selling.
 */
class BuildQuoteRequestFromOrderAction
{
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
            vehicle: $this->order->items->first()?->variant,
        );
    }
}
