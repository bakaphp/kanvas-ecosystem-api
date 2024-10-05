<?php

declare(strict_types=1);

namespace App\GraphQL\Event\Mutations\Events;

use Kanvas\Apps\Models\Apps;
use Kanvas\Event\Events\Actions\CreateEventAction;
use Kanvas\Event\Events\DataTransferObject\Event as DataTransferObjectEvent;
use Kanvas\Event\Events\Models\Event;

class EventManagementMutation
{
    /**
     * Create new lead
     */
    public function create(mixed $root, array $req): Event
    {
        $user = auth()->user();
        $app = app(Apps::class);

        $event = DataTransferObjectEvent::from($app, $user, $user->getCurrentCompany(), $req['input']);

        $createEvent = new CreateEventAction($event);

        return $createEvent->execute();
    }

    public function update(mixed $root, array $req): Event
    {
        $user = auth()->user();
        $app = app(Apps::class);

        //return $lead;
    }

    public function delete(mixed $root, array $req): Event
    {
        $user = auth()->user();
        $app = app(Apps::class);

        //return $lead;
    }
}
