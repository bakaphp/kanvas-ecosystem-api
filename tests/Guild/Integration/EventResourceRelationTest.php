<?php

declare(strict_types=1);

namespace Tests\Guild\Integration;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Event\Events\Models\Event;
use Kanvas\Event\Events\Models\EventResource;
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
        $app = app(Apps::class);
        $user = auth()->user();

        return Event::create([
            'users_id' => $user->getId(),
            'companies_id' => $user->getCurrentCompany()->getId(),
            'apps_id' => $app->getId(),
            'theme_id' => 1,
            'theme_area_id' => 1,
            'event_status_id' => 1,
            'event_type_id' => 1,
            'event_class_id' => 1,
            'event_category_id' => 1,
            'name' => $name,
            'slug' => str($name)->slug() . '-' . uniqid(),
        ]);
    }

    private function seedPeople(): People
    {
        $app = app(Apps::class);
        $user = auth()->user();

        /** @var People $people */
        $people = PeopleFactory::new()
            ->withAppId($app->getId())
            ->withCompanyId($user->getCurrentCompany()->getId())
            ->withUserId($user->getId())
            ->create();

        return $people;
    }

    private function seedOrganization(): Organization
    {
        $app = app(Apps::class);
        $user = auth()->user();

        return Organization::create([
            'apps_id' => $app->getId(),
            'companies_id' => $user->getCurrentCompany()->getId(),
            'users_id' => $user->getId(),
            'name' => 'Event Resource Corp ' . uniqid(),
            'address' => '',
            'total_employees' => 0,
        ]);
    }
}
