<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Mercury\Actions;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Kanvas\Connectors\Mercury\DataTransferObject\MercuryCustomer;
use Kanvas\Connectors\Mercury\Enums\CustomFieldEnum;
use Kanvas\Connectors\Mercury\Services\MercuryCustomerService;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Guild\Organizations\Models\Organization;

/**
 * Find-or-create the Mercury AR customer for a Guild Organization, and remember the mapping.
 *
 * Idempotent on the `MERCURY_CUSTOMER_ID` custom field: once an organization has been pushed, we reuse the
 * id rather than creating a duplicate customer every time an invoice goes out. Mercury will happily accept
 * five customers all called "Initech LLC", and then the AR ageing on their side is split across all five.
 */
class PushCustomerToMercuryAction
{
    public function __construct(
        public readonly AppInterface $app,
        public readonly CompanyInterface $company,
        public readonly Organization $organization,
        protected readonly ?MercuryCustomerService $customerService = null,
    ) {
    }

    public function execute(): string
    {
        $existing = $this->organization->get(CustomFieldEnum::CUSTOMER_ID->value);

        if (! empty($existing)) {
            return (string) $existing;
        }

        $email = trim((string) $this->organization->email);

        if ($email === '') {
            throw new ValidationException(
                "Organization {$this->organization->getId()} ({$this->organization->name}) has no email. "
                . 'Mercury delivers invoices BY email, so a customer without one would produce an invoice '
                . 'nobody ever receives. Add an email before invoicing them through Mercury.'
            );
        }

        $service = $this->customerService ?? new MercuryCustomerService($this->app, $this->company);

        $created = $service->create(
            MercuryCustomer::fromOrganization($this->organization, $email),
        );

        if ($created->id === null) {
            throw new ValidationException('Mercury returned no id for the created customer.');
        }

        $this->organization->set(CustomFieldEnum::CUSTOMER_ID->value, $created->id);

        return $created->id;
    }
}
