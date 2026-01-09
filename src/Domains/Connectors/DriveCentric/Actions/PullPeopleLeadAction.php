<?php

declare(strict_types=1);

namespace Kanvas\Connectors\DriveCentric\Actions;

use Baka\Users\Contracts\UserInterface;
use Illuminate\Support\Facades\DB;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\DriveCentric\Services\CustomerService;
use Kanvas\Guild\Leads\Models\Lead;

class PullPeopleLeadAction
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
    ): ?Lead {
        return DB::transaction(function () use ($email, $phone, $customerId): ?Lead {
            $customer = $this->findCustomer($email, $phone, $customerId);

            $pullPeopleAction = new PullPeopleAction($this->app, $this->company, $this->user);
            $pullPeopleAction->execute(
                email: $email,
                phone: $phone,
                customerId: $customerId
            );

            $lead = null;
            if (! empty($customer['deal']['id'])) {
                $pullLeadAction = new PullLeadAction($this->app, $this->company, $this->user);
                $lead = $pullLeadAction->execute($customer['deal']['id']);
            }

            return $lead;
        });
    }

    protected function findCustomer(
        ?string $email = null,
        ?string $phone = null,
        ?string $customerId = null
    ): array {
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
            return [];
        }

        return $customer;
    }
}
