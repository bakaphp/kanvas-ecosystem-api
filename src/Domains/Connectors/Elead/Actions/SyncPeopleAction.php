<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Elead\Actions;

use Kanvas\Connectors\Elead\Entities\Customer as CustomerEntity;
use Kanvas\Connectors\Elead\Enums\CustomFieldEnum;
use Kanvas\Guild\Customers\Models\People;
use Throwable;

class SyncPeopleAction
{
    public function __construct(
        protected People $people
    ) {
    }

    public function execute(): CustomerEntity
    {
        $eLeadCustomerData = CustomerEntity::convertPeopleToCustomerStructure($this->people);

        try {
            $peopleCustomField = $this->people->getCustomField(CustomFieldEnum::CUSTOMER_ID->value);
            $eLeadCustomerId = $peopleCustomField ? (string) $peopleCustomField->value : null;
        } catch (Throwable $e) {
            $eLeadCustomerId = (string) $this->people->get(CustomFieldEnum::CUSTOMER_ID->value);
        }

        if (empty($eLeadCustomerId)) {
            $eLeadCustomer = CustomerEntity::create($this->people->app, $this->people->company, $eLeadCustomerData);
            $this->people->set(CustomFieldEnum::CUSTOMER_ID->value, $eLeadCustomer->id);
        } else {
            $eLeadCustomer = CustomerEntity::getById($this->people->app, $this->people->company, $eLeadCustomerId);
            $eLeadCustomer->update($eLeadCustomerData);
        }

        return $eLeadCustomer;
    }
}
