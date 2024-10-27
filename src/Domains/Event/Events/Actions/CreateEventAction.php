<?php

declare(strict_types=1);

namespace Kanvas\Event\Events\Actions;

use Baka\Support\Str;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Kanvas\Currencies\Models\Currencies;
use Kanvas\Event\Events\DataTransferObject\Event;
use Kanvas\Event\Events\DataTransferObject\EventVersion;
use Kanvas\Event\Events\Models\Event as ModelsEvent;
use Kanvas\Event\Participants\Actions\CreateParticipantAction;

class CreateEventAction
{
    public function __construct(
        protected Event $event
    ) {
    }

    public function execute(): ModelsEvent
    {
        $event = DB::connection('event')->transaction(function () {
            $slug = $this->event->slug ?? Str::slug($this->event->name);
            //Slug no attached to the event type id , idk why
            $slug = $slug . '-' . $this->event->type->getId();
            // $this->validateSlug($slug);
            $event = ModelsEvent::updateOrCreate([
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
                'slug' => $slug,
            ]);
            if ($this->event->dates->count()) {
                $eventVersionSlug = Str::slug('events-versions-' . $slug . $this->event->dates[0]->date->format('Y-m-d'));
            } else {
                $eventVersionSlug = Str::slug('events-versions-' . $slug);
            }
            $eventVersionAction = new CreateEventVersionAction(
                new EventVersion(
                    event: $event,
                    user: $this->event->user,
                    currency: Currencies::getByCode('USD'),
                    name: $this->event->name,
                    version: 1,
                    description: $this->event->description,
                    pricePerTicket: 0,
                    dates: $this->event->dates,
                    slug: $eventVersionSlug
                )
            );

            $eventVersion = $eventVersionAction->execute();
            foreach ($this->event->participants as $participant) {
                $createParticipant = new CreateParticipantAction(
                    $this->event->app,
                    $this->event->company->defaultBranch,
                    $this->event->user,
                    $this->event->participants,
                    $eventVersion,
                    $participant
                );
                $createParticipant->execute();
            }

            return $event;
        });

        return $event;
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
                            ->table('events')
                            ->where('slug', $value)
                            ->where('apps_id', $this->event->app->getId())
                            ->where('companies_id', $this->event->company->getId())
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
