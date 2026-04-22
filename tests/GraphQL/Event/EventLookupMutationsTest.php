<?php

declare(strict_types=1);

namespace Tests\GraphQL\Event;

use Kanvas\Apps\Models\Apps;
use Kanvas\Event\Events\Models\EventClass;
use Kanvas\Event\Events\Models\EventStatus;
use Kanvas\Event\Events\Models\EventType;
use Kanvas\Event\Participants\Models\ParticipantType;
use Kanvas\Event\Support\Setup;
use Kanvas\Event\Themes\Models\Theme;
use Kanvas\Event\Themes\Models\ThemeArea;
use Tests\TestCase;

class EventLookupMutationsTest extends TestCase
{
    protected function runEventSetup(): void
    {
        $user = auth()->user();
        $app = app(Apps::class);
        $company = $user->getCurrentCompany();
        new Setup($app, $user, $company)->run();
    }

    public function testCreateUpdateDeleteEventType(): void
    {
        $this->runEventSetup();

        $create = $this->graphQL('
            mutation($input: EventLookupInput!) {
                createEventType(input: $input) { id name }
            }
        ', ['input' => ['name' => 'Test Type ' . uniqid()]])->assertSuccessful();
        $id = $create->json('data.createEventType.id');
        $this->assertNotNull($id);

        $this->graphQL('
            mutation($id: ID!, $input: EventLookupUpdateInput!) {
                updateEventType(id: $id, input: $input) { id name }
            }
        ', ['id' => $id, 'input' => ['name' => 'Updated Type']])
            ->assertSuccessful()
            ->assertJson(['data' => ['updateEventType' => ['name' => 'Updated Type']]]);

        $this->graphQL('
            mutation($id: ID!) { deleteEventType(id: $id) }
        ', ['id' => $id])
            ->assertSuccessful()
            ->assertJson(['data' => ['deleteEventType' => true]]);

        $this->assertNull(EventType::find($id));
    }

    public function testCreateUpdateDeleteEventClass(): void
    {
        $this->runEventSetup();

        $create = $this->graphQL('
            mutation($input: EventLookupInput!) {
                createEventClass(input: $input) { id name }
            }
        ', ['input' => ['name' => 'Test Class ' . uniqid(), 'is_default' => false]])
            ->assertSuccessful();
        $id = $create->json('data.createEventClass.id');

        $this->graphQL('
            mutation($id: ID!) { deleteEventClass(id: $id) }
        ', ['id' => $id])
            ->assertSuccessful()
            ->assertJson(['data' => ['deleteEventClass' => true]]);

        $this->assertNull(EventClass::find($id));
    }

    public function testCreateDeleteEventStatus(): void
    {
        $this->runEventSetup();

        $create = $this->graphQL('
            mutation($input: EventLookupInput!) {
                createEventStatus(input: $input) { id name }
            }
        ', ['input' => ['name' => 'Test Status ' . uniqid()]])->assertSuccessful();
        $id = $create->json('data.createEventStatus.id');

        $this->graphQL('
            mutation($id: ID!) { deleteEventStatus(id: $id) }
        ', ['id' => $id])
            ->assertSuccessful();

        $this->assertNull(EventStatus::find($id));
    }

    public function testCreateDeleteEventTheme(): void
    {
        $this->runEventSetup();

        $create = $this->graphQL('
            mutation($input: EventLookupInput!) {
                createEventTheme(input: $input) { id name }
            }
        ', ['input' => ['name' => 'Test Theme ' . uniqid()]])->assertSuccessful();
        $id = $create->json('data.createEventTheme.id');

        $this->graphQL('
            mutation($id: ID!) { deleteEventTheme(id: $id) }
        ', ['id' => $id])->assertSuccessful();

        $this->assertNull(Theme::find($id));
    }

    public function testCreateDeleteEventThemeArea(): void
    {
        $this->runEventSetup();

        $create = $this->graphQL('
            mutation($input: EventLookupInput!) {
                createEventThemeArea(input: $input) { id name }
            }
        ', ['input' => ['name' => 'Test Area ' . uniqid()]])->assertSuccessful();
        $id = $create->json('data.createEventThemeArea.id');

        $this->graphQL('
            mutation($id: ID!) { deleteEventThemeArea(id: $id) }
        ', ['id' => $id])->assertSuccessful();

        $this->assertNull(ThemeArea::find($id));
    }

    public function testCreateUpdateDeleteParticipantType(): void
    {
        $this->runEventSetup();

        $create = $this->graphQL('
            mutation($input: EventLookupInput!) {
                createParticipantType(input: $input) { id name }
            }
        ', ['input' => ['name' => 'VIP ' . uniqid()]])->assertSuccessful();
        $id = $create->json('data.createParticipantType.id');

        $this->graphQL('
            mutation($id: ID!, $input: EventLookupUpdateInput!) {
                updateParticipantType(id: $id, input: $input) { id name }
            }
        ', ['id' => $id, 'input' => ['name' => 'Renamed VIP']])
            ->assertSuccessful()
            ->assertJson(['data' => ['updateParticipantType' => ['name' => 'Renamed VIP']]]);

        $this->graphQL('
            mutation($id: ID!) { deleteParticipantType(id: $id) }
        ', ['id' => $id])->assertSuccessful();

        $this->assertNull(ParticipantType::find($id));
    }

    public function testCreateUpdateDeleteEventCategory(): void
    {
        $this->runEventSetup();
        $user = auth()->user();
        $app = app(Apps::class);
        $company = $user->getCurrentCompany();

        $eventType = EventType::fromApp($app)->fromCompany($company)->first();
        $eventClass = EventClass::fromApp($app)->fromCompany($company)->first();

        $create = $this->graphQL('
            mutation($input: EventCategoryInput!) {
                createEventCategory(input: $input) { id name position }
            }
        ', ['input' => [
            'name' => 'Cat ' . uniqid(),
            'event_type_id' => $eventType->getId(),
            'event_class_id' => $eventClass->getId(),
            'position' => 5,
        ]])->assertSuccessful();
        $id = $create->json('data.createEventCategory.id');
        $this->assertSame(5, (int) $create->json('data.createEventCategory.position'));

        $this->graphQL('
            mutation($id: ID!, $input: EventCategoryUpdateInput!) {
                updateEventCategory(id: $id, input: $input) { id name position }
            }
        ', ['id' => $id, 'input' => ['name' => 'Renamed Cat', 'position' => 10]])
            ->assertSuccessful()
            ->assertJson(['data' => ['updateEventCategory' => ['name' => 'Renamed Cat', 'position' => 10]]]);

        $this->graphQL('
            mutation($id: ID!) { deleteEventCategory(id: $id) }
        ', ['id' => $id])->assertSuccessful();
    }
}
