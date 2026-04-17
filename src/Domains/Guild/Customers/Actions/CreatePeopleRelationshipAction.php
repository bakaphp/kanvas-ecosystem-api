<?php

declare(strict_types=1);

namespace Kanvas\Guild\Customers\Actions;

use Illuminate\Support\Facades\DB;
use Kanvas\Guild\Customers\DataTransferObject\PeopleRelationship as PeopleRelationshipData;
use Kanvas\Guild\Customers\Models\PeopleRelationship;

class CreatePeopleRelationshipAction
{
    public function __construct(
        protected readonly PeopleRelationshipData $data,
    ) {
    }

    public function execute(): PeopleRelationship
    {
        return DB::connection('crm')->transaction(function () {
            $relationship = new PeopleRelationship();
            $relationship->apps_id = $this->data->app->getId();
            $relationship->companies_id = $this->data->company->getId();
            $relationship->name = $this->data->name;
            $relationship->description = $this->data->description;
            $relationship->saveOrFail();

            return $relationship;
        });
    }
}
