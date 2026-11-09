<?php

declare(strict_types=1);

namespace Baka\Support;

use Sentry\Breadcrumb;
use Sentry\Event;

/**
 * Sentry records the full URL of every outgoing HTTP call (breadcrumbs and spans, on by default) and exception
 * messages can quote it. A provider that puts a credential in the URL path (WebPOS's ApiKey routes) would ship
 * it to Sentry on any error in the same request or job, so those URLs are scrubbed before anything is sent.
 * Wired in config/sentry.php as before_breadcrumb / before_send / before_send_transaction.
 */
final class SentryUrlRedactor
{
    public const string REDACTED = '[redacted]';

    /**
     * Path prefixes whose long segments are credentials. Ids that long (a systemRef uuid) go too; the endpoint
     * name and the short codes around them stay readable.
     */
    private const array SECRET_PATHS = [
        '#(webposonline\.com/api/fedo/ak/)([^\s?\#"\'<>]+)#i',
    ];

    private const int SECRET_SEGMENT_MIN_LENGTH = 20;

    public static function redact(string $text): string
    {
        foreach (self::SECRET_PATHS as $pattern) {
            $text = (string) preg_replace_callback(
                $pattern,
                fn (array $match): string => $match[1] . implode('/', array_map(
                    fn (string $segment): string => strlen($segment) >= self::SECRET_SEGMENT_MIN_LENGTH ? self::REDACTED : $segment,
                    explode('/', $match[2])
                )),
                $text
            );
        }

        return $text;
    }

    public static function breadcrumb(Breadcrumb $breadcrumb): ?Breadcrumb
    {
        $message = $breadcrumb->getMessage();
        if ($message !== null) {
            $breadcrumb = $breadcrumb->withMessage(self::redact($message));
        }

        foreach ($breadcrumb->getMetadata() as $name => $value) {
            $breadcrumb = $breadcrumb->withMetadata($name, self::redactValue($value));
        }

        return $breadcrumb;
    }

    public static function event(Event $event): ?Event
    {
        foreach ($event->getExceptions() as $exception) {
            $exception->setValue(self::redact($exception->getValue()));
        }

        $message = $event->getMessage();
        if ($message !== null) {
            $event->setMessage(self::redact($message), $event->getMessageParams(), $event->getMessageFormatted());
        }

        foreach ($event->getSpans() as $span) {
            $span->setDescription($span->getDescription() !== null ? self::redact($span->getDescription()) : null);
            $span->setData(self::redactValue($span->getData()));
        }

        return $event;
    }

    private static function redactValue(mixed $value): mixed
    {
        if (is_string($value)) {
            return self::redact($value);
        }

        return is_array($value) ? array_map(self::redactValue(...), $value) : $value;
    }
}
