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

        /**
         * @todo move to factory
         */
        $people = new Peoples();
        $people->users_id = auth()->user()->getId();
        $people->companies_id = $company->getId();
        $people->name = 'Test People';
        $people->saveOrFail();

        $lead = new Leads();
        $lead->companies_id = $company->getId();
        $lead->companies_branches_id = $company->branch()->firstOrFail()->getId();
        $lead->users_id = auth()->user()->getId();
        $lead->people_id = $people->getId();
        $lead->title = 'Test Lead';
        $lead->leads_receivers_id = 0;
        $lead->leads_owner_id = $lead->users_id;
        $lead->saveOrFail();

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

        /**
         * @todo move to factory
         */
        $people = new Peoples();
        $people->users_id = auth()->user()->getId();
        $people->companies_id = $company->getId();
        $people->name = 'Test People';
        $people->saveOrFail();

        $lead = new Leads();
        $lead->companies_id = $company->getId();
        $lead->companies_branches_id = $company->branch()->firstOrFail()->getId();
        $lead->users_id = auth()->user()->getId();
        $lead->people_id = $people->getId();
        $lead->title = 'Test Lead';
        $lead->leads_receivers_id = 0;
        $lead->leads_owner_id = $lead->users_id;
        $lead->saveOrFail();

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
