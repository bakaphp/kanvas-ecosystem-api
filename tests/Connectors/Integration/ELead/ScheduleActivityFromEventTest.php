<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\ELead;

use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Elead\Actions\ScheduleEleadActivityFromEventAction;
use Kanvas\Connectors\Elead\Actions\SyncLeadAction;
use Kanvas\Connectors\Elead\Actions\SyncPeopleAction;
use Kanvas\Connectors\Elead\Enums\CustomFieldEnum;
use Kanvas\Event\Events\Models\Event;
use Kanvas\Event\Events\Models\EventCategory;
use Kanvas\Event\Events\Models\EventResource;
use Kanvas\Event\Events\Models\EventType;
use Kanvas\Event\Support\Setup;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Guild\LeadSources\Actions\CreateLeadSourceAction;
use Kanvas\Guild\LeadSources\DataTransferObject\LeadSource;
use Tests\Connectors\Traits\HasELeadConfiguration;
use Tests\TestCase;

final class ScheduleActivityFromEventTest extends TestCase
{
    use HasELeadConfiguration;

    private function createEventWithLead(): array
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $this->getClient($app, $company);

        $setup = new Setup($app, $user, $company);
        $setup->run();

        $people = People::factory()->withUserId($user->getId())
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->withContacts(canUseFakeInfo: false)
            ->create();

        new SyncPeopleAction($people)->execute();

        $lead = Lead::factory()
            ->withUserId($user->getId())
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->withPeopleId($people->getId())
            ->create();

        $leadSource = new CreateLeadSourceAction(
            LeadSource::from([
                'name' => 'Lead Link',
                'description' => 'Internet',
                'leads_types_id' => null,
                'is_active' => true,
                'app' => $app,
                'company' => $company,
            ])
        )->execute();
        $lead->leads_sources_id = $leadSource->getId();
        $lead->saveOrFail();

        new SyncLeadAction($lead)->execute();

        $input = [
            'name' => 'Test Event for eLead Activity',
            'description' => 'Scheduling test',
            'category_id' => EventCategory::fromCompany($company)->fromApp($app)->first()->getId(),
            'type_id' => EventType::fromCompany($company)->fromApp($app)->first()->getId(),
            'dates' => [
                [
                    'date' => date('Y-m-d'),
                    'start_time' => '14:00',
                    'end_time' => '15:00',
                ],
            ],
        ];

        $response = $this->graphQL('
            mutation($input: EventInput!) {
                createEvent(input: $input) {
                    id
                    name
                }
            }
        ', ['input' => $input])->assertSuccessful();

        $eventId = $response->json('data.createEvent.id');
        $event = Event::find($eventId);

        EventResource::create([
            'apps_id' => $app->getId(),
            'companies_id' => $company->getId(),
            'event_id' => $event->getId(),
            'resources_id' => $lead->getId(),
            'resources_type' => Lead::class,
        ]);

        return ['event' => $event, 'lead' => $lead->refresh()];
    }

    public function testScheduleActivityFromEvent(): void
    {
        $data = $this->createEventWithLead();
        $event = $data['event'];
        $lead = $data['lead'];

        $this->assertNotEmpty($lead->get(CustomFieldEnum::OPPORTUNITY_ID->value));

        $result = new ScheduleEleadActivityFromEventAction(
            $event,
            $lead,
            ['activity_type_name' => 'Phone Call']
        )->execute();

        $this->assertNotNull($result->activityId);
        $this->assertNotNull($result->app);
        $this->assertNotNull($result->company);
    }

    public function testScheduleActivityUsesEventTypeName(): void
    {
        $data = $this->createEventWithLead();
        $event = $data['event'];
        $lead = $data['lead'];

        $result = new ScheduleEleadActivityFromEventAction(
            $event,
            $lead,
        )->execute();

        $this->assertNotNull($result->activityId);
    }

    public function testScheduleActivityFailsWithoutOpportunityId(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $setup = new Setup($app, $user, $company);
        $setup->run();

        $people = People::factory()->withUserId($user->getId())
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->create();

        $lead = Lead::factory()
            ->withUserId($user->getId())
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->withPeopleId($people->getId())
            ->create();

        $input = [
            'name' => 'Test Event No Opportunity',
            'description' => 'Should fail',
            'category_id' => EventCategory::fromCompany($company)->fromApp($app)->first()->getId(),
            'type_id' => EventType::fromCompany($company)->fromApp($app)->first()->getId(),
            'dates' => [
                [
                    'date' => date('Y-m-d'),
                    'start_time' => '10:00',
                    'end_time' => '11:00',
                ],
            ],
        ];

        $response = $this->graphQL('
            mutation($input: EventInput!) {
                createEvent(input: $input) {
                    id
                }
            }
        ', ['input' => $input])->assertSuccessful();

        $event = Event::find($response->json('data.createEvent.id'));

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Lead does not have an eLead opportunity ID');

        new ScheduleEleadActivityFromEventAction($event, $lead)->execute();
    }
}
