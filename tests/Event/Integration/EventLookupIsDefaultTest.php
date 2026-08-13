<?php

declare(strict_types=1);

namespace Tests\Event\Integration;

use Tests\TestCase;

final class EventLookupIsDefaultTest extends TestCase
{
    public function testCreateEventTypePersistsIsDefault(): void
    {
        $name = 'Type ' . fake()->unique()->uuid();

        $this->graphQL('
            mutation($input: EventLookupInput!) {
                createEventType(input: $input) { id name is_default }
            }
        ', ['input' => ['name' => $name, 'is_default' => true]])
            ->assertSuccessful()
            ->assertJson(['data' => ['createEventType' => ['name' => $name, 'is_default' => true]]]);
    }

    public function testUpdateEventTypeSetsIsDefaultWithoutColumnError(): void
    {
        $name = 'Type ' . fake()->unique()->uuid();

        $created = $this->graphQL('
            mutation($input: EventLookupInput!) {
                createEventType(input: $input) { id }
            }
        ', ['input' => ['name' => $name]])
            ->assertSuccessful()
            ->json('data.createEventType.id');

        $this->graphQL('
            mutation($id: ID!, $input: EventLookupUpdateInput!) {
                updateEventType(id: $id, input: $input) { id is_default }
            }
        ', ['id' => $created, 'input' => ['is_default' => true]])
            ->assertSuccessful()
            ->assertJson(['data' => ['updateEventType' => ['id' => (string) $created, 'is_default' => true]]]);
    }

    public function testUpdateParticipantTypeSetsIsDefaultWithoutColumnError(): void
    {
        $name = 'Participant ' . fake()->unique()->uuid();

        $created = $this->graphQL('
            mutation($input: EventLookupInput!) {
                createParticipantType(input: $input) { id }
            }
        ', ['input' => ['name' => $name]])
            ->assertSuccessful()
            ->json('data.createParticipantType.id');

        $this->graphQL('
            mutation($id: ID!, $input: EventLookupUpdateInput!) {
                updateParticipantType(id: $id, input: $input) { id is_default }
            }
        ', ['id' => $created, 'input' => ['is_default' => true]])
            ->assertSuccessful()
            ->assertJson(['data' => ['updateParticipantType' => ['id' => (string) $created, 'is_default' => true]]]);
    }
}
