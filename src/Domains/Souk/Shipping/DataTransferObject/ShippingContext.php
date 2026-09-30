<?php

declare(strict_types=1);

namespace Kanvas\Souk\Shipping\DataTransferObject;

use Baka\Contracts\CompanyInterface;
use Kanvas\Apps\Models\Apps;
use Kanvas\Regions\Models\Regions;

final readonly class ShippingContext
{
    public function __construct(
        public Apps $app,
        public CompanyInterface $company,
        public Regions $region,
    ) {
    }
}
