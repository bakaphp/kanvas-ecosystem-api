<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Mercury\Actions;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Baka\Users\Contracts\UserInterface;
use Kanvas\Connectors\Mercury\DataTransferObject\MercuryCustomer;
use Kanvas\Connectors\Mercury\Enums\CustomFieldEnum;
use Kanvas\Connectors\Mercury\Services\MercuryCustomerService;
use Kanvas\Guild\Organizations\Models\Organization;

/**
 * Maps Mercury's AR customers onto Guild Organizations.
 *
 * This exists mainly to STOP DUPLICATES. Without it, the first invoice we push to an existing Mercury
 * customer creates a second copy of them, and their AR ageing on Mercury's side silently splits across two
 * records. Matching on email first — which is the one field Mercury requires and the one thing that reliably
 * identifies a billing contact — links them instead.
 *
 * Creating an Organization for an unknown Mercury customer is not fabrication: someone deliberately entered
 * that customer in Mercury, so the relationship is real. We're mirroring it, not inventing it.
 */
class PullMercuryCustomersAction
{
    public function __construct(
        public readonly AppInterface $app,
        public readonly CompanyInterface $company,
        public readonly ?UserInterface $user = null,
        protected readonly ?MercuryCustomerService $customerService = null,
    ) {
    }

    /**
     * @return list<Organization>
     */
    public function execute(): array
    {
        $service = $this->customerService ?? new MercuryCustomerService($this->app, $this->company);

        $organizations = [];

        foreach ($service->list() as $customer) {
            if ($customer->id === null) {
                continue;
            }

            $organizations[] = $this->linkOrCreate($customer);
        }

        return $organizations;
    }

    private function linkOrCreate(MercuryCustomer $customer): Organization
    {
        $mercuryId = (string) $customer->id;

        $existing = $this->findByMercuryId($mercuryId)
            ?? $this->findByEmail($customer->email);

        if ($existing !== null) {
            $existing->set(CustomFieldEnum::CUSTOMER_ID->value, $mercuryId);

            return $existing;
        }

        $organization = Organization::create([
            'apps_id' => $this->app->getId(),
            'companies_id' => $this->company->getId(),
            'users_id' => $this->user?->getId(),
            'name' => $customer->name,
            'email' => $customer->email,
            'address' => '',
            'total_employees' => 0,
        ]);

        $organization->set(CustomFieldEnum::CUSTOMER_ID->value, $customer->id);

        return $organization;
    }

    private function findByMercuryId(string $mercuryCustomerId): ?Organization
    {
        return Organization::query()
            ->where('apps_id', $this->app->getId())
            ->where('companies_id', $this->company->getId())
            ->where('is_deleted', false)
            ->get()
            ->first(
                fn (Organization $o): bool => (string) $o->get(CustomFieldEnum::CUSTOMER_ID->value) === $mercuryCustomerId,
            );
    }

    private function findByEmail(string $email): ?Organization
    {
        if (trim($email) === '') {
            return null;
        }

        return Organization::query()
            ->where('apps_id', $this->app->getId())
            ->where('companies_id', $this->company->getId())
            ->where('is_deleted', false)
            ->where('email', $email)
            ->first();
    }
}
