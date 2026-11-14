<?php

declare(strict_types=1);

namespace Tests\Guild\Leads\Actions;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Event;
use Kanvas\Apps\Models\Apps;
use Kanvas\Guild\Customers\DataTransferObject\Address;
use Kanvas\Guild\Customers\DataTransferObject\Contact;
use Kanvas\Guild\Customers\DataTransferObject\People as PeopleData;
use Kanvas\Guild\Customers\Enums\ContactTypeEnum;
use Kanvas\Guild\Leads\Actions\CreateLeadAction;
use Kanvas\Guild\Leads\DataTransferObject\Lead as LeadData;
use Kanvas\Guild\Leads\Events\LeadCreatedEvent;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Triggers\Events\LeadAiTriggeredEvent;
use Spatie\LaravelData\DataCollection;
use Tests\TestCase;

/**
 * A lead an import brings in (`runWorkflow: false`) is history, not a prospect who just wrote in:
 * nothing that listens for a new lead may hear about it.
 */
final class CreateLeadAnnouncementTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = [null, 'crm', 'social', 'workflow', 'intelligence'];

    protected function setUp(): void
    {
        parent::setUp();

        Event::fake([LeadCreatedEvent::class, LeadAiTriggeredEvent::class]);

        // Agents other tests left on the shared company must not reach out for this one.
        Agent::query()
            ->servingCompany(app(Apps::class), auth()->user()->getCurrentCompany())
            ->get()
            ->filter(fn (Agent $agent): bool => $agent->reachesOutToNewLeads())
            ->each(fn (Agent $agent) => $agent->switchReachOut(false));
    }

    public function testALeadCreatedByHandIsAnnounced(): void
    {
        $lead = new CreateLeadAction($this->leadData(runWorkflow: true))->execute();

        Event::assertDispatched(LeadCreatedEvent::class, fn (LeadCreatedEvent $event): bool => $event->lead->is($lead));
        Event::assertDispatched(LeadAiTriggeredEvent::class);
    }

    public function testALeadAnAgentReachesOutToIsNotGivenItsAiModeASecondTime(): void
    {
        Agent::factory()
            ->withAppId(app(Apps::class)->getId())
            ->withCompanyId(auth()->user()->getCurrentCompany()->getId())
            ->create()
            ->switchReachOut();

        new CreateLeadAction($this->leadData(runWorkflow: true))->execute();

        Event::assertDispatched(LeadCreatedEvent::class);
        Event::assertNotDispatched(LeadAiTriggeredEvent::class);
    }

    public function testAnImportedLeadIsNotAnnounced(): void
    {
        new CreateLeadAction($this->leadData(runWorkflow: false))->execute();

        Event::assertNotDispatched(LeadCreatedEvent::class);
        Event::assertNotDispatched(LeadAiTriggeredEvent::class);
    }

    private function leadData(bool $runWorkflow): LeadData
    {
        $user = auth()->user();
        $app = app(Apps::class);
        $branch = $user->getCurrentCompany()->defaultBranch;

        return new LeadData(
            app: $app,
            branch: $branch,
            user: $user,
            title: 'Announcement ' . uniqid(),
            pipeline_stage_id: 0,
            people: new PeopleData(
                app: $app,
                branch: $branch,
                user: $user,
                firstname: 'Ana',
                contacts: Contact::collect(
                    [[
                        'value' => '+1809555' . random_int(1000, 9999),
                        'contacts_types_id' => ContactTypeEnum::CELLPHONE->value,
                        'weight' => 100,
                    ]],
                    DataCollection::class
                ),
                address: Address::collect([], DataCollection::class),
                lastname: 'Import ' . uniqid(),
            ),
            leads_owner_id: $user->getId(),
            runWorkflow: $runWorkflow,
        );
    }
}
