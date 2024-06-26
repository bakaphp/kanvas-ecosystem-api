<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Shopify\Actions;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Kanvas\Inventory\Regions\Models\Regions;

class SyncShopifyOrderAction
{
    public function __construct(
        protected AppInterface $app,
        protected CompanyInterface $company,
        protected Regions $region,
        protected array $orderData
    ) {
    }

    public function execute()
    {
        $syncCustomer = new SyncShopifyCustomerAction(
            $this->app, 
            $this->company,
            $this->region,
            $this->orderData['customer']
        );
        $customer = $syncCustomer->execute();

        print_R($customer->toArray());
        print_r($this->orderData);
        die();
    }
}
