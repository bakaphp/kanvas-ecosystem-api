<?php

declare(strict_types=1);

namespace Kanvas\Connectors\DealerSocket\Actions;

use Kanvas\Connectors\DealerSocket\Services\DealerSocketCustomerService;
use Kanvas\Guild\Customers\Models\People;

class PushPeopleAction
{
    public function __construct(
        protected People $people
    ) {
    }

    public function execute(): array
    {
        $pushLead = new DealerSocketCustomerService(
            $this->people->app,
            $this->people->company,
        );

        return $pushLead->saveCustomer($this->people);
    }
}
