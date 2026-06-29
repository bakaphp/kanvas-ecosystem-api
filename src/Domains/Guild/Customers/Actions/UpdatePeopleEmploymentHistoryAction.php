<?php

declare(strict_types=1);

namespace Kanvas\Guild\Customers\Actions;

use Kanvas\Guild\Customers\DataTransferObject\PeopleEmploymentHistory as PeopleEmploymentHistoryData;
use Kanvas\Guild\Customers\Models\PeopleEmploymentHistory;

class UpdatePeopleEmploymentHistoryAction
{
    public function __construct(
        protected readonly PeopleEmploymentHistory $employmentHistory,
        protected readonly PeopleEmploymentHistoryData $data
    ) {
    }

    public function execute(): PeopleEmploymentHistory
    {
        $this->employmentHistory->update([
            'organizations_id' => $this->data->organization->getId(),
            'position' => $this->data->position,
            'income' => $this->data->income,
            'start_date' => $this->data->start_date,
            'end_date' => $this->data->end_date,
            'status' => $this->data->status,
            'income_type' => $this->data->income_type,
        ]);

        return $this->employmentHistory->refresh();
    }
}
