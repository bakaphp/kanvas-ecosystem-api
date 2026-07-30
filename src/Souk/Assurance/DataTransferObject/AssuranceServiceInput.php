<?php

declare(strict_types=1);

namespace Kanvas\Souk\Assurance\DataTransferObject;

use Spatie\LaravelData\Data;

class AssuranceServiceInput extends Data
{
    /**
     * @param int $order_id
     * @param string $product
     * @param string $service_type
     * @param array $payload
     */
    public function __construct(
        public int $order_id,
        public string $product,
        public string $service_type,
        public array $payload,
    ) {
    }
}
