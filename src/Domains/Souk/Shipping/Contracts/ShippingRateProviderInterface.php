<?php

declare(strict_types=1);

namespace Kanvas\Souk\Shipping\Contracts;

use Kanvas\Souk\Shipping\DataTransferObject\ShipmentRequest;
use Kanvas\Workflow\Enums\IntegrationsEnum;

interface ShippingRateProviderInterface
{
    public function name(): string;

    public function integration(): ?IntegrationsEnum;

    public function supports(ShipmentRequest $request): bool;

    public function quote(ShipmentRequest $request): array;
}
