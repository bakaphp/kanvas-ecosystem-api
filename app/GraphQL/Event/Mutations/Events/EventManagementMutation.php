<?php

declare(strict_types=1);

namespace App\GraphQL\Event\Mutations\Events;

use Kanvas\Apps\Models\Apps;
use Kanvas\Event\Events\Actions\CreateEventAction;
use Kanvas\Event\Events\Actions\UpdateEventAction;
use Kanvas\Event\Events\DataTransferObject\Event as DataTransferObjectEvent;
use Kanvas\Event\Events\Models\Event;

class EventManagementMutation
{
    public function create(mixed $root, array $req): Event
    {
        $user = auth()->user();
        $app = app(Apps::class);

        $event = DataTransferObjectEvent::fromMultiple(
            $app,
            $user,
            $user->getCurrentCompany(),
            $req['input']
        );

        $createEvent = new CreateEventAction($event);

        return $createEvent->execute();
    }

    public function update(mixed $root, array $req): Event
    {
        $user = auth()->user();
        $app = app(Apps::class);
        $company = $user->getCurrentCompany();
        $input = $req['input'];

        $event = Event::getByIdFromCompanyApp($req['id'], $company, $app);
        $eventVersion = $event->versions()->first();

        $updateData = array_filter([
            'name' => $input['name'] ?? null,
            'description' => $input['description'] ?? null,
            'resources_id' => $input['resources_id'] ?? null,
            'resources_type' => $input['resources_type'] ?? null,
            'dates' => $input['dates'] ?? null,
        ], fn ($value) => $value !== null);

        new UpdateEventAction($eventVersion, $updateData)->execute();

        return $event->fresh();
    }

    public function delete(mixed $root, array $req): bool
    {
        $user = auth()->user();
        $app = app(Apps::class);

        return Event::getByIdFromCompanyApp($req['id'], $user->getCurrentCompany(), $app)->delete();
    }
}
