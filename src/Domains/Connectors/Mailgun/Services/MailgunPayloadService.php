<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Mailgun\Services;

/**
 * Reads Mailgun's parsed-email payload. Header keys arrive in whatever case the sending client used
 * (`Message-Id`, `message-id`, `Message-ID`), so every header read goes through a case-insensitive
 * lookup instead of an array key that works for Gmail and misses for Outlook.
 */
class MailgunPayloadService
{
    private array $payload;

    public function __construct(mixed $payload)
    {
        $this->payload = is_array($payload) ? $payload : [];
    }

    public function sender(): string
    {
        return strtolower(trim((string) ($this->payload['sender'] ?? '')));
    }

    /**
     * The display name Mailgun parsed off the From header, for naming a People we have to create.
     */
    public function senderName(): ?string
    {
        $from = trim((string) ($this->payload['from'] ?? ''));

        if ($from === '' || ! preg_match('/^\s*"?([^"<]*?)"?\s*</', $from, $matches)) {
            return null;
        }

        $name = trim($matches[1]);

        return $name === '' ? null : $name;
    }

    public function subject(): string
    {
        return trim((string) ($this->payload['subject'] ?? ''));
    }

    /**
     * `stripped-text` is Mailgun's body with the quoted thread and signature removed. Feeding the
     * agent the raw body instead means every reply re-reads the entire conversation as new input —
     * the token bill grows with the thread and the model starts answering old questions.
     */
    public function text(): string
    {
        $stripped = trim((string) ($this->payload['stripped-text'] ?? ''));

        return $stripped !== '' ? $stripped : trim((string) ($this->payload['body-plain'] ?? ''));
    }

    public function messageId(): string
    {
        return $this->header('Message-Id');
    }

    public function inReplyTo(): string
    {
        return $this->header('In-Reply-To');
    }

    public function references(): string
    {
        return $this->header('References');
    }

    /**
     * Vacation responders, bounce notices and list mail. An agent that answers these ends up in a
     * loop with another robot, on the customer's Mailgun bill.
     */
    public function isAutoReply(): bool
    {
        $autoSubmitted = strtolower($this->header('Auto-Submitted'));

        if ($autoSubmitted !== '' && $autoSubmitted !== 'no') {
            return true;
        }

        if ($this->header('X-Autoreply') !== '' || $this->header('X-Autorespond') !== '') {
            return true;
        }

        if (in_array(strtolower($this->header('Precedence')), ['bulk', 'auto_reply', 'junk', 'list'], true)) {
            return true;
        }

        return $this->header('List-Unsubscribe') !== '';
    }

    public function header(string $name): string
    {
        $name = strtolower($name);

        foreach ($this->payload as $key => $value) {
            if (is_string($key) && strtolower($key) === $name && is_scalar($value)) {
                return trim((string) $value);
            }
        }

        // `message-headers` is Mailgun's raw [[name, value], …] dump — the only place a header lands
        // when it isn't one of the fields Mailgun promotes to the top level.
        $raw = $this->payload['message-headers'] ?? null;
        $raw = is_string($raw) ? json_decode($raw, true) : $raw;

        if (! is_array($raw)) {
            return '';
        }

        foreach ($raw as $header) {
            if (is_array($header)
                && isset($header[0], $header[1])
                && is_string($header[0])
                && strtolower($header[0]) === $name
                && is_scalar($header[1])
            ) {
                return trim((string) $header[1]);
            }
        }

        return '';
    }
}
