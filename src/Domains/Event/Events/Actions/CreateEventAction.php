<?php

declare(strict_types=1);

namespace Kanvas\Event\Events\Actions;

use Illuminate\Support\Facades\DB;
use Kanvas\Currencies\Models\Currencies;
use Kanvas\Event\Events\DataTransferObject\Event;
use Kanvas\Event\Events\DataTransferObject\EventVersion;
use Kanvas\Event\Events\Models\Event as ModelsEvent;

class CreateEventAction
{
    public function __construct(
        protected Event $event
    ) {
    }

    public function execute(): ModelsEvent
    {
        $event = DB::connection('event')->transaction(function () {
            $event = ModelsEvent::create([
                'apps_id' => $this->event->app->getId(),
                'companies_id' => $this->event->company->getId(),
                'users_id' => $this->event->user->getId(),
                'name' => $this->event->name,
                'theme_id' => $this->event->theme->getId(),
                'theme_area_id' => $this->event->themeArea->getId(),
                'event_status_id' => $this->event->status->getId(),
                'event_type_id' => $this->event->type->getId(),
                'event_category_id' => $this->event->category->getId(),
                'event_class_id' => $this->event->class->getId(),
                'description' => $this->event->description,
            ]);

            $eventVersion = new CreateEventVersionAction(
                new EventVersion(
                    event: $event,
                    user: $this->event->user,
                    currency: Currencies::getByCode('USD'),
                    name: $this->event->name,
                    version: 1,
                    description: $this->event->description,
                    pricePerTicket: 0,
                    dates: $this->event->dates
                )
            );

            $eventVersion->execute();

            return $event;
        });

        return $event;
    }
}