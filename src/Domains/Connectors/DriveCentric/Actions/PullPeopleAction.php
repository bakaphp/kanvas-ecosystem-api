<?php

declare(strict_types=1);

namespace Kanvas\Connectors\DriveCentric\Actions;

use Baka\Contracts\AppInterface;
use Baka\Users\Contracts\UserInterface;
use Illuminate\Support\Facades\DB;
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
        protected AppInterface $app,
        protected Companies $company,
        protected UserInterface $user,
    ) {
        $this->customerService = new CustomerService($this->company, $this->app);
    }

    /**
     * Execute pull people action by email or phone.
     */
    public function execute(?string $email = null, ?string $phone = null): array
    {
        return DB::transaction(function () use ($email, $phone) {
            $customer = null;

            if ($email) {
                $customer = $this->customerService->getCustomerByEmail($email);
            }

            if (! $customer && $phone) {
                $customer = $this->customerService->getCustomerByPhone($phone);
            }

            if (! $customer) {
                return [];
            }

            return [$this->syncCustomer($customer)];
        });
    }

    /**
     * Pull people by DriveCentric customer ID.
     */
    public function executeById(string $customerId): PeopleModel
    {
        return DB::transaction(function () use ($customerId) {
            $customer = $this->customerService->getCustomerById($customerId);

            if (! $customer) {
                throw new DriveCentricException("Customer with ID {$customerId} not found");
            }

            return $this->syncCustomer($customer);
        });
    }

    /**
     * Pull multiple customers by search criteria.
     */
    public function executeBySearch(array $filters = []): array
    {
        return DB::transaction(function () use ($filters) {
            $customers = $this->customerService->search($filters);

            $syncedPeople = [];
            foreach ($customers as $customer) {
                try {
                    $syncedPeople[] = $this->syncCustomer($customer);
                } catch (\Throwable $e) {
                    report($e);
                }
            }

            return $syncedPeople;
        });
    }

    /**
     * Pull customers from a deal.
     */
    public function executeByDealId(string $dealId): array
    {
        return DB::transaction(function () use ($dealId) {
            $customers = $this->customerService->getCustomersByDealId($dealId);

            $syncedPeople = [];
            foreach ($customers as $customer) {
                try {
                    $syncedPeople[] = $this->syncCustomer($customer);
                } catch (\Throwable $e) {
                    report($e);
                }
            }

            return $syncedPeople;
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
