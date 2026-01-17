<?php

declare(strict_types=1);

namespace Kanvas\Connectors\DriveCentric\Actions;

use Kanvas\Connectors\DriveCentric\DataTransferObject\People as PeopleDTO;
use Kanvas\Connectors\DriveCentric\Enums\CustomFieldEnums;
use Kanvas\Connectors\DriveCentric\Exceptions\DriveCentricException;
use Kanvas\Connectors\DriveCentric\Services\CustomerService;
use Kanvas\Guild\Customers\Models\People;

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
        $customerResult = $this->customerService->updateCustomer($customerId, $customerData);

        // Update credit app with driver's license if available
        $creditAppResult = $this->updateCreditAppIfNeeded($customerId);

        return [
            'customer' => $customerResult,
            'creditApp' => $creditAppResult,
        ];
    }

    /**
     * Update the credit app with driver's license data if available.
     */
    protected function updateCreditAppIfNeeded(string $customerId): ?array
    {
        $driverLicenseData = $this->people->getDriverLicenseData();

        if ($driverLicenseData === null) {
            return null;
        }

        $creditAppPayload = [
            'drivingLicense' => $driverLicenseData,
        ];

        return $this->customerService->addCreditApp($customerId, $creditAppPayload);
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
