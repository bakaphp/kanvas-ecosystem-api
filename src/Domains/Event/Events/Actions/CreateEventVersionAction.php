<?php

declare(strict_types=1);

namespace Kanvas\Event\Events\Actions;

use Kanvas\Event\Events\DataTransferObject\EventVersion;
use Kanvas\Event\Events\Models\EventVersion as ModelsEventVersion;

class CreateEventVersionAction
{
    public function __construct(
        protected EventVersion $eventVersion,
    ) {
    }

    public function execute(): ModelsEventVersion
    {
        $eventVersion = ModelsEventVersion::updateOrCreate([
            'apps_id' => $this->eventVersion->event->app->getId(),
            'companies_id' => $this->eventVersion->event->company->getId(),
            'users_id' => $this->eventVersion->user->getId(),
            'event_id' => $this->eventVersion->event->getId(),
            'currency_id' => $this->eventVersion->currency->getId(),
            'name' => $this->eventVersion->name,
            'version' => $this->eventVersion->version,
            'description' => $this->eventVersion->description,
            'slug' => $this->eventVersion->slug ?? null,
            'price_per_ticket' => $this->eventVersion->pricePerTicket,
            'agenda' => $this->eventVersion->agenda ?? null,
            'metadata' => $this->eventVersion->metadata ?? null,
        ]);

        $eventVersion->addDates($this->eventVersion->dates);

        return $eventVersion;
    }
}
