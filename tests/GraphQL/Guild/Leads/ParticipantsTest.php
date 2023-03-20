<?php

declare(strict_types=1);

namespace Tests\GraphQL\Guild\Leads;

use Kanvas\Guild\Customers\Models\Peoples;
use Kanvas\Guild\Leads\Models\Leads;
use Tests\TestCase;

class ParticipantsTest extends TestCase
{
    /**
     * testSave.
     */
    public function testAddParticipants(): void
    {
        $company = auth()->user()->getCurrentCompany();

        $people = Peoples::factory()->create([
            'companies_id' => $company->getId(),
            'users_id' => auth()->user()->getId(),
        ]);

        $lead = Leads::factory()->create([
            'companies_id' => $company->getId(),
            'companies_branches_id' => $company->branch()->firstOrFail()->getId(),
            'users_id' => auth()->user()->getId(),
            'people_id' => $people->getId(),
            'leads_receivers_id' => 0,
            'leads_owner_id' => auth()->user()->getId(),
        ]);

        //print_r($people->toArray()); die();
    }
}
