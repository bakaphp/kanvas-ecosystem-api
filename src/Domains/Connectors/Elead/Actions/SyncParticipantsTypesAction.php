<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Elead\Actions;

use Baka\Contracts\AppInterface;
use Kanvas\Companies\Models\Companies;
use Kanvas\Guild\Leads\Models\LeadType;

class SyncParticipantsTypesAction
{
    public function __construct(
        protected AppInterface $app,
        protected Companies $company
    ) {
    }

    public function execute(): void
    {
        $relationshipType = [
            -1 => 'Co-buyer',
            2 => 'Spouse',
            3 => 'Child',
            4 => 'Parent',
            5 => 'Other relative',
            6 => 'Friend',
            7 => 'Other',
            8 => 'Referral',
            9 => 'Nearest relative',
            10 => 'Employee',
            11 => 'Co-worker',
        ];

        foreach ($relationshipType as $key => $value) {
            LeadType::firstOrCreate(
                [
                   'name' => $value,
                   'apps_id' => $this->app->getId(),
                   'companies_id' => $this->company->getId(),
                ],
                [
                   'description' => $key,
                ]
            );

            //set custom field for relationship
            //$newSource->set(Flag::LEAD_RELATIONSHIP_TYPE, $key);
        }
    }
}
