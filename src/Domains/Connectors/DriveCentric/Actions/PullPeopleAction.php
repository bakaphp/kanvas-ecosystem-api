<?php

declare(strict_types=1);

namespace Kanvas\Connectors\DriveCentric\Actions;

use Baka\Contracts\AppInterface;
use Baka\Users\Contracts\UserInterface;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\DriveCentric\DataTransferObject\People;
use Kanvas\Connectors\DriveCentric\Services\CustomerService;
use Kanvas\Guild\Customers\Actions\SyncPeopleByThirdPartyCustomFieldAction;

class PullPeopleAction
{
    public function __construct(
        protected AppInterface $app,
        protected Companies $company,
        protected UserInterface $user,
    ) {
    }

    public function execute(?string $email = null, ?string $phone = null): array
    {
        $customerService = new CustomerService($this->company, $this->app);
        if ($email) {
            $customer = $customerService->getCustomerByEmail($email);
        } else {
            $customer = $customerService->getCustomerByPhone($phone);
        }
        $peopleDto = People::fromDriveCentric(
            $this->app,
            $this->company,
            $this->user,
            $customer
        );

        return [
            new SyncPeopleByThirdPartyCustomFieldAction($peopleDto)->execute(),
        ];
    }
}
