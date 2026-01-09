<?php

declare(strict_types=1);

namespace Kanvas\Connectors\DriveCentric\Actions;

use Kanvas\Connectors\DriveCentric\DataTransferObject\People as PeopleDTO;
use Kanvas\Connectors\DriveCentric\Enums\CustomFieldEnums;
use Kanvas\Connectors\DriveCentric\Exceptions\DriveCentricException;
use Kanvas\Connectors\DriveCentric\Services\CustomerService;
use Kanvas\Guild\Customers\Models\People;

/**
 * PushPeopleAction handles pushing/updating people to DriveCentric.
 *
 * IMPORTANT: In DriveCentric, customers CANNOT be created independently.
 * Customers are only created when a lead/deal is created via LeadService.
 * This action only handles UPDATING existing customers that were created through leads.
 *
 * To create a new customer in DriveCentric:
 * 1. Use PushLeadAction to create a lead (this creates the customer automatically)
 * 2. The customer ID will be stored on the People record
 * 3. Then use this action to update the customer data
 */
class PushPeopleAction
{
    protected CustomerService $customerService;

    public function __construct(
        protected People $people
    ) {
        $this->customerService = new CustomerService(
            $this->people->company,
            $this->people->app
        );
    }

    /**
     * Execute the action to update the person in DriveCentric.
     *
     * @throws DriveCentricException if the customer doesn't exist in DriveCentric
     */
    public function execute(): array
    {
        $customerId = $this->getCustomerId();

        if (! $customerId) {
            throw new DriveCentricException(
                'Customer does not exist in DriveCentric. Create a lead first using PushLeadAction ' .
                'to create the customer, then use this action to update.'
            );
        }

        $customerData = PeopleDTO::toDriveCentric($this->people);

        return $this->customerService->updateCustomer($customerId, $customerData);
    }

    /**
     * Get the DriveCentric customer ID if it exists.
     */
    public function getCustomerId(): ?string
    {
        return $this->people->get(CustomFieldEnums::DRIVE_CENTRIC_CUSTOMER_ID->value);
    }

    /**
     * Get the DriveCentric deal ID if it exists.
     */
    public function getDealId(): ?string
    {
        return $this->people->get(CustomFieldEnums::DRIVE_CENTRIC_DEAL_ID->value);
    }

    /**
     * Check if the person has been synced to DriveCentric (has a customer ID).
     */
    public function isSynced(): bool
    {
        return $this->getCustomerId() !== null;
    }
}
