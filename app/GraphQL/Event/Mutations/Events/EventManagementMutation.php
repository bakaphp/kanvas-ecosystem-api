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

        $event = Event::getByIdFromCompanyApp($req['id'], $user->getCurrentCompany(), $app);
        /**
         * @todo complete
         */
        //$eventDto = DataTransferObjectEvent::from($app, $user, $user->getCurrentCompany(), $req['input']);

        $event->name = $req['input']['name'];
        $event->description = $req['input']['description'] ?? null;
        $event->saveOrFail();

        return $event;
    }

    public function delete(mixed $root, array $req): bool
    {
        $user = auth()->user();
        $app = app(Apps::class);

        return Event::getByIdFromCompanyApp($req['id'], $user->getCurrentCompany(), $app)->delete();
    }
}
