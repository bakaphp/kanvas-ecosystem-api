<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Google\Actions;

use Baka\Contracts\CompanyInterface;
use Carbon\CarbonInterface;
use Kanvas\Connectors\Google\Enums\ConfigurationEnum;
use Kanvas\Exceptions\ValidationException;
use Spatie\GoogleCalendar\Event as GoogleCalendarEvent;
use Throwable;

class CreateGoogleCalendarMeetingAction
{
    public function __construct(
        protected CompanyInterface $company,
        protected string $name,
        protected array $attendeeEmails,
        protected CarbonInterface $startDateTime,
        protected CarbonInterface $endDateTime,
        protected ?string $description = null,
        protected bool $withMeetLink = true,
        protected ?string $externalEventId = null,
    ) {
    }

    public function execute(): array
    {
        $this->applyCompanyCalendarConfig();

        if ($this->externalEventId !== null) {
            try {
                $existing = GoogleCalendarEvent::find($this->externalEventId);
                if ($existing !== null) {
                    return $this->formatResult($existing);
                }
            } catch (Throwable $e) {
                if ((int) $e->getCode() !== 404) {
                    throw $e;
                }

                // Missing is expected on the first execution or after external deletion.
            }
        }

        $emails = array_values(array_unique(array_filter(
            $this->attendeeEmails,
            fn ($email) => is_string($email) && filter_var($email, FILTER_VALIDATE_EMAIL) !== false,
        )));

        $event = new GoogleCalendarEvent();
        $event->name = $this->name;
        $event->description = $this->description;
        $event->startDateTime = $this->startDateTime;
        $event->endDateTime = $this->endDateTime;

        if ($this->externalEventId !== null) {
            $event->googleEvent->setId($this->externalEventId);
        }

        foreach ($emails as $email) {
            $event->addAttendee(['email' => $email]);
        }

        if ($this->withMeetLink) {
            $event->addMeetLink();
        }

        $saved = $event->save($this->externalEventId !== null ? 'insertEvent' : null);

        return $this->formatResult($saved, $emails);
    }

    protected function formatResult(GoogleCalendarEvent $saved, ?array $emails = null): array
    {
        return [
            'id' => $saved->id,
            'calendar_id' => $saved->getCalendarId(),
            'name' => $saved->name,
            'description' => $saved->description,
            'start' => $this->startDateTime->toIso8601String(),
            'end' => $this->endDateTime->toIso8601String(),
            'meet_link' => $saved->googleEvent->getHangoutLink(),
            'html_link' => $saved->googleEvent->getHtmlLink(),
            'attendees' => $emails ?? $this->attendeeEmails,
        ];
    }

    protected function applyCompanyCalendarConfig(): void
    {
        $config = $this->company->get(ConfigurationEnum::GOOGLE_CALENDAR_CONFIG->value);

        if (! is_array($config) || empty($config)) {
            throw new ValidationException(
                'Google Calendar config not found for company ' . $this->company->name,
            );
        }

        config(['google-calendar' => $config]);
    }
}
