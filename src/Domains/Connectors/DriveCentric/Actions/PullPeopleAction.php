<?php

declare(strict_types=1);

namespace Kanvas\Connectors\DriveCentric\Actions;

use Baka\Users\Contracts\UserInterface;
use Illuminate\Support\Facades\DB;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\DriveCentric\DataTransferObject\People;
use Kanvas\Connectors\DriveCentric\Exceptions\DriveCentricException;
use Kanvas\Connectors\DriveCentric\Services\CustomerService;
use Kanvas\Guild\Customers\Actions\SyncPeopleByThirdPartyCustomFieldAction;
use Kanvas\Guild\Customers\Models\People as PeopleModel;

class PullPeopleAction
{
    protected CustomerService $customerService;

    public function __construct(
        protected Apps $app,
        protected Companies $company,
        protected UserInterface $user,
    ) {
        $this->customerService = new CustomerService($this->company, $this->app);
    }

    public function execute(
        ?string $email = null,
        ?string $phone = null,
        ?string $customerId = null
    ): PeopleModel {
        return DB::transaction(function () use ($email, $phone, $customerId): PeopleModel {
            $customer = null;

            // First try by customer ID (direct lookup)
            if ($customerId) {
                $customer = $this->customerService->getCustomerById($customerId);
            }

            // Then try by email
            if (! $customer && $email) {
                $customer = $this->customerService->getCustomerByEmail($email);
            }

            // Finally try by phone
            if (! $customer && $phone) {
                $customer = $this->customerService->getCustomerByPhone($phone);
            }

            if (! $customer) {
                throw new DriveCentricException(
                    'Customer not found in DriveCentric with provided criteria'
                );
            }

            return $this->syncCustomer($customer);
        });
    }

    /**
     * Sync a single customer to Kanvas People.
     */
    protected function syncCustomer(array $customer): PeopleModel
    {
        $peopleDto = People::fromDriveCentric(
            $this->app,
            $this->company,
            $this->user,
            $customer
        );

        $people = new SyncPeopleByThirdPartyCustomFieldAction($peopleDto)->execute();
        $people->searchable();

        return $people;
    }
}
