<?php

declare(strict_types=1);

namespace Kanvas\Guild\Customers\Actions;

use Kanvas\Exceptions\ValidationException;
use Kanvas\Guild\Customers\DataTransferObject\People;
use Kanvas\Guild\Customers\Models\People as ModelsPeople;

class SyncPeopleByThirdPartyCustomFieldAction
{
    public function __construct(
        protected People $people,
    ) {
    }

    public function execute(): ModelsPeople
    {
        $customFields = $this->people->custom_fields ?? [];
        $customFieldKeys = array_keys($customFields);
        $customFieldValues = array_values($customFields);

        if (empty($customFieldKeys[0]) || empty($customFieldValues[0])) {
            throw new ValidationException('People Missing Custom Fields Key and Value to find reference');
        }

        $people = ModelsPeople::getByCustomField(
            $customFieldKeys[0],
            $customFieldValues[0],
            $this->people->branch->company,
        );

        if ($people !== null) {
            $this->people->id = $people->getId();
        }

        $createPeople = new CreatePeopleAction($this->people);

        return $createPeople->execute();
    }
}
