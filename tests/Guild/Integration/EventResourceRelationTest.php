<?php

declare(strict_types=1);

namespace Tests\Guild\Integration;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Event\Events\Models\Event;
use Kanvas\Event\Events\Models\EventCategory;
use Kanvas\Event\Events\Models\EventClass;
use Kanvas\Event\Events\Models\EventResource;
use Kanvas\Event\Events\Models\EventStatus;
use Kanvas\Event\Events\Models\EventType;
use Kanvas\Event\Themes\Models\Theme;
use Kanvas\Event\Themes\Models\ThemeArea;
use Kanvas\Guild\Customers\Factories\PeopleFactory;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Organizations\Models\Organization;
use Tests\TestCase;

final class EventResourceRelationTest extends TestCase
{
    use DatabaseTransactions;

    protected array $connectionsToTransact = ['mysql', 'crm', 'event'];

    private const PEOPLE_EVENTS_QUERY = '
        query people($id: Mixed) {
            peoples(where: { column: ID, operator: EQ, value: $id }) {
                data {
                    id
                    events { id name }
                }
            }
        }
    ';

    private const ORGANIZATION_EVENTS_QUERY = '
        query organizations($id: Mixed) {
            organizations(where: { column: ID, operator: EQ, value: $id }) {
                data {
                    id
                    events { id name }
                }
            }
        }
    ';

    public function testPeopleResolvesTheEventsItIsAttachedToAsAResource(): void
    {
        $people = $this->seedPeople();
        $event = $this->seedEvent('People Resource Event');

        $this->attachResource($event, People::class, $people->getId());

        $events = $people->events()->get();

        $this->assertCount(1, $events);
        $this->assertSame($event->getId(), $events->first()->getId());
    }

    public function testOrganizationResolvesTheEventsItIsAttachedToAsAResource(): void
    {
        $organization = $this->seedOrganization();
        $event = $this->seedEvent('Organization Resource Event');

        $this->attachResource($event, Organization::class, $organization->getId());

        $events = $organization->events()->get();

        $this->assertCount(1, $events);
        $this->assertSame($event->getId(), $events->first()->getId());
    }

    /**
     * The morph is keyed on the class name, so a People and an Organization sharing an id must not
     * pick up each other's events.
     */
    public function testTheMorphDoesNotBleedBetweenPeopleAndOrganization(): void
    {
        $people = $this->seedPeople();
        $organization = $this->seedOrganization();
        $event = $this->seedEvent('People Only Event');

        $this->attachResource($event, People::class, $people->getId());
        $this->attachResource($event, Organization::class, $organization->getId());

        $strayEvent = $this->seedEvent('Organization Only Event');
        $this->attachResource($strayEvent, Organization::class, $organization->getId());

        $this->assertCount(1, $people->events()->get());
        $this->assertCount(2, $organization->events()->get());
    }

    public function testEventsAreExposedOnBothGraphQLTypes(): void
    {
        $people = $this->seedPeople();
        $organization = $this->seedOrganization();
        $event = $this->seedEvent('Graph Exposed Event');

        $this->attachResource($event, People::class, $people->getId());
        $this->attachResource($event, Organization::class, $organization->getId());

        $this->graphQL(self::PEOPLE_EVENTS_QUERY, ['id' => $people->getId()])
            ->assertSuccessful()
            ->assertGraphQLErrorFree()
            ->assertJsonPath('data.peoples.data.0.events.0.id', (string) $event->getId());

        $this->graphQL(self::ORGANIZATION_EVENTS_QUERY, ['id' => $organization->getId()])
            ->assertSuccessful()
            ->assertGraphQLErrorFree()
            ->assertJsonPath('data.organizations.data.0.events.0.id', (string) $event->getId());
    }

    private function attachResource(Event $event, string $resourceType, int $resourceId): EventResource
    {
        return EventResource::create([
            'apps_id' => $event->apps_id,
            'companies_id' => $event->companies_id,
            'event_id' => $event->getId(),
            'resources_id' => $resourceId,
            'resources_type' => $resourceType,
        ]);
    }

    private function seedEvent(string $name): Event
    {
        $context = $this->tenantContext();
        $eventType = EventType::firstOrCreate($context + ['name' => 'Event Resource Type']);
        $eventClass = EventClass::firstOrCreate($context + ['name' => 'Event Resource Class']);

        return Event::create($context + [
            'theme_id' => Theme::firstOrCreate($context + ['name' => 'Event Resource Theme'])->getId(),
            'theme_area_id' => ThemeArea::firstOrCreate($context + ['name' => 'Event Resource Area'])->getId(),
            'event_status_id' => EventStatus::firstOrCreate($context + ['name' => 'Event Resource Status'])->getId(),
            'event_type_id' => $eventType->getId(),
            'event_class_id' => $eventClass->getId(),
            'event_category_id' => EventCategory::firstOrCreate(
                $context + [
                    'name' => 'Event Resource Category',
                    'event_type_id' => $eventType->getId(),
                    'event_class_id' => $eventClass->getId(),
                ]
            )->getId(),
            'name' => $name,
            'slug' => str($name)->slug() . '-' . uniqid(),
        ]);
    }

    private function tenantContext(): array
    {
        $user = auth()->user();

        return [
            'apps_id' => app(Apps::class)->getId(),
            'companies_id' => $user->getCurrentCompany()->getId(),
            'users_id' => $user->getId(),
        ];
    }

    private function seedPeople(): People
    {
        $context = $this->tenantContext();

        /** @var People $people */
        $people = PeopleFactory::new()
            ->withAppId($context['apps_id'])
            ->withCompanyId($context['companies_id'])
            ->withUserId($context['users_id'])
            ->create();

        return $people;
    }

    private function seedOrganization(): Organization
    {
        return Organization::create($this->tenantContext() + [
            'name' => 'Event Resource Corp ' . uniqid(),
            'address' => '',
            'total_employees' => 0,
        ]);
    }
}
