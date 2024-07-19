<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Shopify\DataTransferObject;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Kanvas\Inventory\Regions\Models\Regions;

class Shopify
{
    /**
     * __construct.
     *
     * @return void
     */
    public function __construct(
        public CompanyInterface $company,
        public AppInterface $app,
        public Regions $region,
        public string $apiKey,
        public string $apiSecret,
        public string $shopUrl,
    ) {
    }

    /**
     * fromArray.
     */
    public static function viaRequest(array $data, AppInterface $app, CompanyInterface $company): self
    {
        return new self(
            $company,
            $app,
            Regions::getById($data['region_id']),
            $data['client_id'],
            $data['client_secret'],
            $data['shop_url'],
        );
    }
}
