<?php

declare(strict_types=1);

namespace Kanvas\Event\Events\Actions;

use Baka\Support\DateHelper;
use Baka\Support\Str;
use Illuminate\Support\Facades\Notification;
use Kanvas\Event\Events\Enums\ConfigurationEnum;
use Kanvas\Event\Events\Enums\EmailTemplateEnum;
use Kanvas\Event\Events\Models\Event;
use Kanvas\Event\Events\Models\EventVersion;
use Kanvas\Event\Events\Notifications\EventParticipantNotification;
use Kanvas\Event\Participants\Models\Participant;
use Kanvas\Notifications\Models\NotificationTypes;
use Kanvas\SystemModules\Repositories\SystemModulesRepository;

class SendEventEmailsAction
{
    private const string TIME_FORMAT = 'g:i A';

    private array $channels = ['mail'];

    public function __construct(
        private EventVersion $eventVersion,
        private string $emailTemplate = EmailTemplateEnum::PARTICIPANT_NOTIFICATION->value,
        private array $data = []
    ) {
    }

    public function execute(?Participant $participant = null): void
    {
        if (! ConfigurationEnum::emailsEnabled($this->eventVersion->app)) {
            return;
        }

        // Load necessary relations to ensure they're available in email templates.
        // `event.resource` stays out: Event::resource() is morphTo('resources'), so an eager load
        // files the result under `resources` and leaves `resource` null. Lazy loading reads it fine.
        $this->eventVersion->load([
            'event.eventStatus',
            'event.eventCategory',
            'event.eventType',
            'event.eventClass',
            'event.theme',
            'event.themeArea',
            'event.resources',
            'dates',
            'participants.people',
        ]);

        $event = $this->eventVersion->event;

        // If a specific participant is provided, send email only to that participant
        if ($participant !== null) {
            $participant->load('people');
            $participantEmail = $participant->people->getEmails()->first()?->value;

            if ($participantEmail) {
                $this->sendEmail(
                    $this->emailTemplate,
                    $participantEmail,
                    $this->buildPayload($event, $participant),
                    $event,
                    $participant
                );
            }

            return;
        }

        // Otherwise, send emails to all participants
        $participants = $this->eventVersion->participants;

        foreach ($participants as $participant) {
            $participantEmail = $participant->people->getEmails()->first()?->value;

            if ($participantEmail) {
                $this->sendEmail(
                    $this->emailTemplate,
                    $participantEmail,
                    $this->buildPayload($event, $participant),
                    $event,
                    $participant
                );
            }
        }
    }

    protected function buildPayload(Event $event, Participant $participant): array
    {
        return [
            'template' => $this->emailTemplate,
            'event' => $event,
            'event_version' => $this->eventVersion,
            'participant' => $participant,
            'event_name' => $this->eventVersion->name,
            'participant_name' => $participant->people->name ?? 'Participant',
            'participant_id' => $participant->id,
            'resource' => $event->resource,
            'resources' => $event->resources,
            'event_dates' => $this->eventVersion->dates,
            ...$this->localizedDates($event),
            ...$this->data,
        ];
    }

    /**
     * Emails are the one place with no client to convert for us, so the times have to be rendered
     * in the event's own timezone here — otherwise a 9 AM booking in Santo Domingo reads as
     * 1:00 PM to the customer.
     */
    protected function localizedDates(Event $event): array
    {
        $firstDate = $this->eventVersion->dates->first();
        $firstEndTime = DateHelper::normalizeTime((string) $firstDate?->end_time, self::TIME_FORMAT);

        if ($this->eventVersion->start_at === null) {
            return [
                'start_date' => $firstDate?->event_date?->format('Y-m-d'),
                'start_time' => DateHelper::normalizeTime((string) $firstDate?->start_time, self::TIME_FORMAT),
                'end_time' => $firstEndTime,
            ];
        }

        $timezone = $event->getTimezone();
        $startAt = $this->eventVersion->start_at->clone()->setTimezone($timezone);
        $endAt = $this->eventVersion->end_at?->clone()->setTimezone($timezone);

        return [
            'start_date' => $startAt->format('Y-m-d'),
            'start_time' => $startAt->format(self::TIME_FORMAT),
            'end_time' => $endAt?->format(self::TIME_FORMAT) ?? $firstEndTime,
            'timezone' => $timezone,
        ];
    }

    protected function sendEmail(
        string $emailTemplateName,
        string $email,
        array $mailData,
        Event $event,
        Participant $participant
    ): void {
        $notification = new EventParticipantNotification(
            $event,
            $participant,
            $mailData,
        );
        $notification->setTemplateName($emailTemplateName);
        $notification->setType(NotificationTypes::firstOrCreate([
            'apps_id' => $event->app->getId(),
            'key' => $event::class,
            'name' => Str::simpleSlug($event::class),
            'system_modules_id' => SystemModulesRepository::getByModelName($event::class, $event->app)->getId(),
            'is_deleted' => 0,
        ], [
            'template' => $emailTemplateName,
        ])->name);

        $notification->channels = $this->channels;

        Notification::route('mail', $email)->notify($notification);
    }
}
