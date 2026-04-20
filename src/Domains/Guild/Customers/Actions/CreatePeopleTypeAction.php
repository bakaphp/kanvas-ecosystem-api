<?php

declare(strict_types=1);

namespace Kanvas\Guild\Customers\Actions;

use Kanvas\Guild\Customers\DataTransferObject\PeopleType as PeopleTypeDto;
use Kanvas\Guild\Customers\Models\PeopleType;

class CreatePeopleTypeAction
{
    public function __construct(
        private PeopleTypeDto $data
    ) {
    }

    public function execute(): PeopleType
    {
        $peopleType = new PeopleType();
        $peopleType->apps_id = $this->data->apps->getId();
        $peopleType->companies_id = $this->data->companies->getId();
        $peopleType->users_id = $this->data->user->getId();
        $peopleType->name = $this->data->name;
        $peopleType->description = $this->data->description;
        $peopleType->is_active = $this->data->is_active;
        $peopleType->is_default = $this->data->is_default;
        $peopleType->config = $this->data->config;
        $peopleType->saveOrFail();

        return $peopleType;
    }
}
