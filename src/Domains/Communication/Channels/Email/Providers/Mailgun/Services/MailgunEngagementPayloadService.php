<?php

declare(strict_types=1);

namespace Kanvas\Communication\Channels\Email\Providers\Mailgun\Services;

use Baka\Support\Str;
use Illuminate\Support\Carbon;

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

    /** `temporary` or `permanent` on a `failed` event — Mailgun retries the first, the second is final. */
    public function severity(): string
    {
        return strtolower(trim((string) ($this->eventData['severity'] ?? '')));
    }

    /** The receiving server's SMTP code on a failure (550, 421…), or Mailgun's own (605 for a suppressed address). */
    public function deliveryStatusCode(): ?int
    {
        $code = $this->eventData['delivery-status']['code'] ?? null;

        return is_numeric($code) ? (int) $code : null;
    }

    public function deliveryStatusMessage(): ?string
    {
        $status = $this->eventData['delivery-status'] ?? [];

        return Str::trimToNull((string) ($status['description'] ?? ''))
            ?? Str::trimToNull((string) ($status['message'] ?? ''))
            ?? Str::trimToNull((string) ($this->eventData['reason'] ?? ''));
    }

    public function occurredAt(): ?Carbon
    {
        $timestamp = $this->eventData['timestamp'] ?? null;

        return is_numeric($timestamp) ? Carbon::createFromTimestamp((float) $timestamp) : null;
    }

    /** @return array<string, mixed> */
    public function eventData(): array
    {
        return $this->eventData;
    }

    /**
     * Mailgun's bot verdict for an open/click: `apple` (Mail Privacy Protection prefetch), `gmail`,
     * `generic` (a firewall or antivirus scanning the mail on arrival), or empty for a human. Those
     * prefetches fire `opened` seconds after delivery whether or not anyone read the message.
     */
    public function bot(): string
    {
        return strtolower(trim((string) ($this->eventData['client-info']['bot'] ?? '')));
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
