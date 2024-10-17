<?php

declare(strict_types=1);

namespace Kanvas\Event\Events\Actions;

use Baka\Support\Str;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
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
        $slug = $this->eventVersion->slug ?? Str::slug($this->eventVersion->name);

        $this->validateSlug($slug);

        $eventVersion = ModelsEventVersion::create([
            'apps_id' => $this->eventVersion->event->app->getId(),
            'companies_id' => $this->eventVersion->event->company->getId(),
            'users_id' => $this->eventVersion->user->getId(),
            'event_id' => $this->eventVersion->event->getId(),
            'currency_id' => $this->eventVersion->currency->getId(),
            'name' => $this->eventVersion->name,
            'version' => $this->eventVersion->version,
            'description' => $this->eventVersion->description,
            'price_per_ticket' => $this->eventVersion->pricePerTicket,
            'agenda' => $this->eventVersion->agenda ?? null,
            'metadata' => $this->eventVersion->metadata ?? null,
            'slug' => $slug,
        ]);

        $eventVersion->addDates($this->eventVersion->dates);

        return $eventVersion;
    }

    protected function validateSlug(string $slug): void
    {
        Validator::make(
            ['slug' => $slug],
            [
                'slug' => [
                    'required',
                    // Custom rule using DB to specify the connection and validate uniqueness.
                    function ($attribute, $value, $fail) {
                        $exists = DB::connection('event') // Replace with your DB connection name.
                            ->table('event_versions')
                            ->where('slug', $value)
                            ->where('apps_id', $this->eventVersion->event->app->getId())
                            ->where('companies_id', $this->eventVersion->event->company->getId())
                            ->exists();

                        if ($exists) {
                            $fail('The ' . $attribute . ' has already been taken.');
                        }
                    },
                ],
            ]
        )->validate();
    }
}
