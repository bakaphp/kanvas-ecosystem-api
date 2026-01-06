<?php

declare(strict_types=1);

namespace Kanvas\Connectors\DealerSocket\Actions;

use Baka\Contracts\AppInterface;
use Baka\Users\Contracts\UserInterface;
use InvalidArgumentException;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\DealerSocket\DataTransferObject\People as DataTransferObjectPeople;
use Kanvas\Connectors\DealerSocket\Enums\CustomFieldEnum;
use Kanvas\Connectors\DealerSocket\Services\DealerSocketCustomerService;
use Kanvas\Guild\Customers\Actions\SyncPeopleByThirdPartyCustomFieldAction;
use Kanvas\Guild\Customers\Models\People;

class PullPeopleAction
{
    public function __construct(
        protected AppInterface $app,
        protected Companies $company,
        protected UserInterface $user,
    ) {
    }

    public function execute(
        string|int|null $customerId = null,
        ?String $email = null,
        ?string $phoneNumber = null
    ): People {
        if ($customerId === null && $email === null && $phoneNumber === null) {
            throw new InvalidArgumentException('At least one parameter must be provided');
        }
        $customerService = new DealerSocketCustomerService($this->app, $this->company);
        $people = null;

        if ($customerId !== null) {
            $customerData = $customerService->getCustomerById($customerId);
        } else {
            $customerData = $customerService->searchCustomerByPhone($phoneNumber);
        }

        /*         elseif ($email !== null) {
                    /* $customerService = new DealerSocketCustomerService($this->app, $this->company);
                    $customerData = $customerService->searchCustomerByEmail($email);
                    throw new InvalidArgumentException('Search by email not implemented yet');
                } */

        if (isset($customerData['entityId'])) {
            $people = People::getByCustomField(
                CustomFieldEnum::DEALER_SOCKET_CUSTOMER_ID->value,
                $customerData['entityId'],
                $this->company
            );
        }

        if (empty($customerData) || empty($customerData['firstName']) || empty($customerData['emails'])) {
            throw new InvalidArgumentException('No customer data found in DealerSocket');
        }

        if ($people === null) {
            $peopleData = DataTransferObjectPeople::fromMultiple(
                $this->user,
                $this->app,
                $this->company,
                $customerData
            );
            $peopleSync = new SyncPeopleByThirdPartyCustomFieldAction($peopleData);
            $people = $peopleSync->execute();
        }

        return $people;
    }
}
