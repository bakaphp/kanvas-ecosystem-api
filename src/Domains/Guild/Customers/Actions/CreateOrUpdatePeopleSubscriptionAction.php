<?php

declare(strict_types=1);

namespace Kanvas\Guild\Customers\Actions;

use Kanvas\Guild\Customers\DataTransferObject\PeopleSubscription as PeopleSubscriptionDTO;
use Kanvas\Guild\Customers\Models\PeopleSubscription;

class CreateOrUpdatePeopleSubscriptionAction
{
    public function __construct(
        private PeopleSubscriptionDTO $peopleSubscriptionDTO
    ) {
    }

    public function handle(): PeopleSubscription
    {
        $dataPeopleSub = [
                 'subscription_type' => $this->peopleSubscriptionDTO->subscription_type,
                 'status' => '1',
                 'first_date' => $this->peopleSubscriptionDTO->first_date,
                 'start_date' => $this->peopleSubscriptionDTO->start_date,
                 'end_date' => $this->peopleSubscriptionDTO->end_date,
                 'next_renewal' => $this->peopleSubscriptionDTO->next_renewal,
                 'metadata' => $this->peopleSubscriptionDTO->metadata,
                 'apps_id' => $this->peopleSubscriptionDTO->app->getId(),
             ];

        return PeopleSubscription::updateOrCreate(
            $dataPeopleSub,
            [
                'peoples_id' => $this->peopleSubscriptionDTO->people->getId(),
                'subscription_type' => $this->peopleSubscriptionDTO->subscription_type,
            ]
        );
    }
}
