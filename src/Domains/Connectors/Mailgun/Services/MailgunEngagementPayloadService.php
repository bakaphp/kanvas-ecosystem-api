<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Mailgun\Services;

/**
 * Reads Mailgun's event-webhook payload (opened/clicked/delivered/…) — a JSON body shaped as
 * `{signature: {...}, event-data: {...}}`, structurally nothing like the form-encoded inbound-route
 * payload `MailgunPayloadService` reads. Kept as its own class rather than branching inside that one:
 * the two never share a field name, so a shared reader would just be two unrelated APIs in one file.
 */
class MailgunEngagementPayloadService
{
    private array $eventData;

    public function __construct(mixed $payload)
    {
        $payload = is_array($payload) ? $payload : [];
        $this->eventData = is_array($payload['event-data'] ?? null) ? $payload['event-data'] : [];
    }

    public function event(): string
    {
        return strtolower(trim((string) ($this->eventData['event'] ?? '')));
    }

    public function recipient(): string
    {
        return strtolower(trim((string) ($this->eventData['recipient'] ?? '')));
    }

    public function messageId(): string
    {
        return (string) ($this->eventData['message']['headers']['message-id'] ?? '');
    }

    /**
     * The event's own id — stable across Mailgun's retries of the same delivery, unlike messageId()
     * which repeats for every open of the same email. Dedupe on this, not the message.
     */
    public function eventId(): string
    {
        return (string) ($this->eventData['id'] ?? '');
    }

    /**
     * One of the `v:*` variables the sender stamped on the message — Mailgun echoes the whole set
     * back on every event for it. This is the only link between an "opened" event and the People or
     * Lead the mail was about; without it the event is just an address and a timestamp.
     */
    public function userVariable(string $name): ?string
    {
        $value = $this->eventData['user-variables'][$name] ?? null;

        return is_scalar($value) ? (string) $value : null;
    }
}
