<?php

declare(strict_types=1);

namespace Kanvas\Guild\Customers\Actions;

use Illuminate\Support\Facades\DB;
use Kanvas\Guild\Customers\DataTransferObject\PeopleRelationship as PeopleRelationshipData;
use Kanvas\Guild\Customers\Models\PeopleRelationship;

class UpdatePeopleRelationshipAction
{
    public function __construct(
        protected readonly PeopleRelationship $relationship,
        protected readonly PeopleRelationshipData $data,
    ) {
    }

    public function execute(): PeopleRelationship
    {
        return DB::connection('crm')->transaction(function () {
            $this->relationship->name = $this->data->name;
            $this->relationship->description = $this->data->description;
            $this->relationship->saveOrFail();

            return $this->relationship;
        });
    }
}
