<?php

declare(strict_types=1);

namespace Kanvas\Guild\Customers\Actions;

use Kanvas\Guild\Customers\DataTransferObject\PeopleEmploymentHistory as PeopleEmploymentHistoryData;
use Kanvas\Guild\Customers\Models\PeopleEmploymentHistory;

class CreatePeopleEmploymentHistoryAction
{
    public function __construct(
        protected readonly PeopleEmploymentHistoryData $data
    ) {
    }

    public function execute(): PeopleEmploymentHistory
    {
        return $this->data->people->employmentHistory()->updateOrCreate(
            [
                'organizations_id' => $this->data->organization->getId(),
                'apps_id' => $this->data->app->getId(),
                'position' => $this->data->position,
            ],
            [
                'income' => $this->data->income,
                'start_date' => $this->data->start_date,
                'end_date' => $this->data->end_date,
                'status' => $this->data->status,
                'income_type' => $this->data->income_type,
            ]
        );
    }
}
