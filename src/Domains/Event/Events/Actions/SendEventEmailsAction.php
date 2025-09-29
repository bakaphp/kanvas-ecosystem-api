<?php

declare(strict_types=1);

namespace Kanvas\Event\Events\Actions;

use Baka\Support\Str;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Log;
use Kanvas\Event\Events\Enums\EmailTemplateEnum;
use Kanvas\Event\Events\Models\Event;
use Kanvas\Event\Events\Models\EventVersion;
use Kanvas\Event\Events\notifications\EventParticipantNotification;
use Kanvas\Event\Participants\Models\Participant;
use Kanvas\Notifications\Models\NotificationTypes;
use Kanvas\SystemModules\Repositories\SystemModulesRepository;

class SendEventEmailsAction
{
    private array $channels = ['mail'];

    public function __construct(
        private EventVersion $eventVersion,
        private string $emailTemplate = EmailTemplateEnum::PARTICIPANT_NOTIFICATION
    ) {
    }

    public function execute(): void {
        // Load necessary relations to ensure they're available in email templates
        $this->eventVersion->load([
            'event.eventStatus',
            'event.eventCategory',
            'event.eventType',
            'event.eventClass',
            'event.theme',
            'event.themeArea',
            'event.resource',
            'event.resources',
            'dates',
            'participants.people'
        ]);

        $event = $this->eventVersion->event;
        // Include soft-deleted participants for cancellation emails
        $participants = $this->emailTemplate === EmailTemplateEnum::BOOKING_CANCELLED->value
            ? $this->eventVersion->participants()->withTrashed()->get()
            : $this->eventVersion->participants;

        foreach ($participants as $participant) {
            $participantEmail = $participant->people->getEmails()->first()?->value;

            if ($participantEmail) {
                $payload = [
                    'template' => $this->emailTemplate,
                    'event' => $event,
                    'event_version' => $this->eventVersion,
                    'participant' => $participant,
                    'event_name' => $this->eventVersion->name,
                    'participant_name' => $participant->people->name ?? 'Participant',
                    'resource' => $event->resource,
                    'resources' => $event->resources,
                    'event_dates' => $this->eventVersion->dates,
                    'start_date' => $this->eventVersion->dates->first()?->event_date?->format('Y-m-d'),
                    'start_time' => $this->eventVersion->dates->first()?->start_time,
                    'end_time' => $this->eventVersion->dates->first()?->end_time,
                ];

                $this->sendEmail(
                    $this->emailTemplate,
                    $participantEmail,
                    $payload,
                    $event,
                    $participant
                );
            }
        }
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
