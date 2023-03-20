<?php

declare(strict_types=1);

namespace Tests\Guild\Integration;

use Kanvas\Apps\Models\Apps;
use Kanvas\Guild\Customers\Models\Peoples;
use Kanvas\Guild\Leads\Actions\AddLeadParticipantAction;
use Kanvas\Guild\Leads\Actions\RemoveLeadParticipantAction;
use Kanvas\Guild\Leads\DataTransferObject\LeadsParticipant;
use Kanvas\Guild\Leads\Models\Leads;
use Kanvas\Guild\Leads\Models\LeadsParticipants;
use Tests\TestCase;

final class LeadParticipantsTest extends TestCase
{
    public function testAddParticipant(): void
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

        $addParticipant = new AddLeadParticipantAction(
            new LeadsParticipant(
                app(Apps::class),
                $company,
                auth()->user(),
                $lead,
                $people
            )
        );

        $this->assertInstanceOf(LeadsParticipants::class, $addParticipant->execute());
    }

    public function testRemoveParticipant(): void
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

        $leadParticipant = new LeadsParticipant(
            app(Apps::class),
            $company,
            auth()->user(),
            $lead,
            $people
        );

        (new AddLeadParticipantAction(
            $leadParticipant
        ))->execute();

        $removeParticipant = new RemoveLeadParticipantAction($leadParticipant);
        $this->assertTrue($removeParticipant->execute());
    }
}
