<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Shopify\DataTransferObject;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
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
     *
     * @param  array $data
     *
     * @return self
     */
    public static function viaRequest(array $data): self
    {
        return new self(
            isset($data['companies_id']) ? Companies::getById($data['companies_id']) : auth()->user()->getCurrentCompany(),
            app(Apps::class),
            Regions::getById($data['region_id']),
            $data['client_id'],
            $data['client_secret'],
            $data['shop_name'],
        );
    }
}
