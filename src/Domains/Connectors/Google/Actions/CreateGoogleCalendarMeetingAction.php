<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Google\Actions;

use Baka\Contracts\CompanyInterface;
use Carbon\CarbonInterface;
use Google\Client;
use Google\Service\Calendar;
use Google\Service\Calendar\ConferenceData;
use Google\Service\Calendar\CreateConferenceRequest;
use Google\Service\Calendar\Event;
use Google\Service\Calendar\EventAttendee;
use Kanvas\Connectors\Google\Enums\ConfigurationEnum;
use Kanvas\Exceptions\ValidationException;
use RuntimeException;
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
        $config = $this->getCompanyCalendarConfig();
        $calendarId = $this->resolveCalendarId($config);
        $service = $this->createCalendarService($config);

        $emails = $this->sanitizeEmails($this->attendeeEmails);

        $event = new Event([
            'summary' => $this->name,
            'description' => $this->description,
            'start' => [
                'dateTime' => $this->startDateTime->toRfc3339String(),
                'timeZone' => $this->startDateTime->getTimezone()->getName(),
            ],
            'end' => [
                'dateTime' => $this->endDateTime->toRfc3339String(),
                'timeZone' => $this->endDateTime->getTimezone()->getName(),
            ],
            'attendees' => array_map(
                static fn (string $email): EventAttendee => new EventAttendee(['email' => $email]),
                $emails,
            ),
        ]);

        if ($this->withMeetLink) {
            $event->setConferenceData(new ConferenceData([
                'createRequest' => new CreateConferenceRequest([
                    'requestId' => bin2hex(random_bytes(16)),
                    'conferenceSolutionKey' => ['type' => 'hangoutsMeet'],
                ]),
            ]));
        }

        if ($this->externalEventId !== null) {
            $event->setId($this->externalEventId);
        }

        try {
            $saved = $this->externalEventId !== null
                ? $this->updateEvent($service, $calendarId, $this->externalEventId, $event)
                : $this->insertEvent($service, $calendarId, $event);
        } catch (Throwable $exception) {
            if ($this->externalEventId !== null && (int) $exception->getCode() === 404) {
                $saved = $this->insertEvent($service, $calendarId, $event);
            } elseif (! $this->shouldRetryWithoutMeet($exception)) {
                throw $exception;
            } else {
                $this->withMeetLink = false;
                unset($event->conferenceData);
                $saved = $this->externalEventId !== null
                    ? $this->updateEvent($service, $calendarId, $this->externalEventId, $event)
                    : $this->insertEvent($service, $calendarId, $event);
            }
        }

        return $this->formatResult($saved, $calendarId, $emails);
    }

    /** @param array<int, mixed> $emails */
    protected function sanitizeEmails(array $emails): array
    {
        return array_values(array_unique(array_filter(
            $emails,
            fn ($email) => is_string($email) && filter_var($email, FILTER_VALIDATE_EMAIL) !== false,
        )));
    }

    /** @param array<int, string> $emails */
    protected function formatResult(Event $saved, string $calendarId, array $emails): array
    {
        return [
            'id' => $saved->getId(),
            'calendar_id' => $calendarId,
            'name' => $saved->getSummary(),
            'description' => $saved->getDescription(),
            'start' => $this->startDateTime->toIso8601String(),
            'end' => $this->endDateTime->toIso8601String(),
            'meet_link' => $saved->getHangoutLink(),
            'html_link' => $saved->getHtmlLink(),
            'attendees' => $emails,
        ];
    }

    /** @return array<string, mixed> */
    protected function getCompanyCalendarConfig(): array
    {
        $config = $this->company->get(ConfigurationEnum::GOOGLE_CALENDAR_CONFIG->value);

        if (! is_array($config) || empty($config)) {
            throw new ValidationException(
                'Google Calendar config not found for company ' . $this->company->name,
            );
        }

        return $config;
    }

    /** @param array<string, mixed> $config */
    protected function createCalendarService(array $config): Calendar
    {
        $client = new Client();
        $client->setApplicationName((string) ($config['application_name'] ?? 'Kanvas Calendar'));
        $client->setScopes([Calendar::CALENDAR]);
        $client->setAccessType('offline');

        $profile = (string) ($config['default_auth_profile'] ?? 'service_account');
        $profileConfig = $config['auth_profiles'][$profile] ?? $config;
        if (! is_array($profileConfig)) {
            throw new ValidationException("Google Calendar auth profile '{$profile}' is invalid.");
        }

        $credentials = $profileConfig['credentials_json'] ?? $profileConfig['credentials'] ?? null;
        if (is_string($credentials) && str_starts_with(trim($credentials), '{')) {
            $credentials = json_decode($credentials, true, flags: JSON_THROW_ON_ERROR);
        }
        if (! is_array($credentials) && ! is_string($credentials)) {
            throw new ValidationException("Google Calendar credentials are missing for auth profile '{$profile}'.");
        }
        $client->setAuthConfig($credentials);

        $subject = $profileConfig['user_to_impersonate'] ?? $config['user_to_impersonate'] ?? null;
        if (is_string($subject) && $subject !== '') {
            $client->setSubject($subject);
        }

        if ($profile === 'oauth') {
            $token = $profileConfig['token_json'] ?? $profileConfig['token'] ?? null;
            if (is_string($token) && str_starts_with(trim($token), '{')) {
                $token = json_decode($token, true, flags: JSON_THROW_ON_ERROR);
            } elseif (is_string($token) && is_file($token)) {
                $token = json_decode((string) file_get_contents($token), true, flags: JSON_THROW_ON_ERROR);
            }
            if (! is_array($token)) {
                throw new ValidationException('Google Calendar OAuth token is missing or invalid.');
            }
            $client->setAccessToken($token);

            if ($client->isAccessTokenExpired()) {
                $refreshToken = $client->getRefreshToken();
                if (! is_string($refreshToken) || $refreshToken === '') {
                    throw new ValidationException('Google Calendar OAuth token expired and has no refresh token.');
                }
                $client->fetchAccessTokenWithRefreshToken($refreshToken);
            }
        }

        return new Calendar($client);
    }

    /** @param array<string, mixed> $config */
    protected function resolveCalendarId(array $config): string
    {
        $calendarId = $config['calendar_id'] ?? null;
        if (! is_string($calendarId) || trim($calendarId) === '') {
            throw new ValidationException('Google Calendar calendar_id is missing.');
        }

        return trim($calendarId);
    }

    protected function insertEvent(Calendar $service, string $calendarId, Event $event): Event
    {
        try {
            return $service->events->insert($calendarId, $event, [
                'conferenceDataVersion' => $this->withMeetLink ? 1 : 0,
                'sendUpdates' => 'all',
            ]);
        } catch (Throwable $exception) {
            throw new RuntimeException('Google Calendar event creation failed: ' . $exception->getMessage(), 0, $exception);
        }
    }

    protected function shouldRetryWithoutMeet(Throwable $exception): bool
    {
        return $this->withMeetLink
            && str_contains(strtolower($exception->getMessage()), 'invalid conference type');
    }

    protected function findEvent(Calendar $service, string $calendarId, string $eventId): Event
    {
        return $service->events->get($calendarId, $eventId);
    }

    protected function updateEvent(Calendar $service, string $calendarId, string $eventId, Event $event): Event
    {
        try {
            return $service->events->update($calendarId, $eventId, $event, [
                'conferenceDataVersion' => $this->withMeetLink ? 1 : 0,
                'sendUpdates' => 'all',
            ]);
        } catch (Throwable $exception) {
            throw new RuntimeException('Google Calendar event update failed: ' . $exception->getMessage(), (int) $exception->getCode(), $exception);
        }
    }
}
