<?php

declare(strict_types=1);

namespace Baka\Validations;

use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class EmailDomain
{
    /**
     * Minimum local-part length before the random-string heuristic runs.
     * Short handles (initials, nicknames) are never flagged.
     */
    public const SPAM_LOCAL_MIN_LENGTH = 10;

    /**
     * Known disposable / abuse email domains. These never belong to a real
     * signup, so they are rejected regardless of app configuration. Apps can
     * extend this list via the `blocked_email_domains` setting.
     */
    public const BLOCKED_DOMAINS = [
        'web-library.net',
        'tempmail.com',
        'temp-mail.org',
        'guerrillamail.com',
        'guerrillamail.net',
        'guerrillamail.org',
        'guerrillamail.biz',
        'sharklasers.com',
        'yopmail.com',
        'yopmail.fr',
        'yopmail.net',
        'mailinator.com',
        'mailinator.net',
        'mailinator.org',
        '10minutemail.com',
        '10minutemail.net',
        '10minutemail.org',
        'maildrop.cc',
        'harakirimail.com',
        'tempinbox.com',
        'trashmail.com',
        'trashmail.net',
        'trashmail.org',
        'spamgourmet.com',
        'dispostable.com',
        'fakeinbox.com',
        'fakeinbox.net',
        'fakeinbox.org',
        'throwawaymail.com',
        'deadaddress.com',
        'spambox.us',
        'tempemail.net',
        'fakemailgenerator.com',
        'wegwerfmail.de',
        'wegwerfmail.net',
        'getnada.com',
        'mohmal.com',
        'emailondeck.com',
        'moakt.com',
    ];

    /**
     * Verify if email domain is valid.
     */
    public static function verifyDomain(string $email): bool
    {
        if (! checkdnsrr(array_pop(explode('@', $email . '.')), 'MX')) {
            throw new ValidationException('Email domain is not valid.');
        }

        return true;
    }

    /**
     * Whether the email's domain is on the built-in blocklist or any of the
     * extra (per-app configured) blocked domains.
     */
    public static function isBlockedDomain(string $email, array $extraBlocked = []): bool
    {
        if (! str_contains($email, '@')) {
            return false;
        }

        $domain = Str::lower(trim(Str::afterLast($email, '@')));

        if ($domain === '') {
            return false;
        }

        $blocked = array_map(
            static fn (string $d): string => Str::lower(trim($d)),
            array_merge(self::BLOCKED_DOMAINS, $extraBlocked)
        );

        return in_array($domain, $blocked, true);
    }

    /**
     * Detect bot-generated, randomized local parts (e.g. `8rhpkhzq6sqwcx3`).
     * Tuned to be conservative: a long handle with no vowel at all, or one that
     * is overwhelmingly digits with no human separator, is treated as spam.
     * Real handles this long virtually always contain a vowel or a separator.
     */
    public static function hasSpamLocalPart(string $email): bool
    {
        if (! str_contains($email, '@')) {
            return false;
        }

        $local = Str::lower(Str::before($email, '@'));
        $length = strlen($local);

        if ($length < self::SPAM_LOCAL_MIN_LENGTH) {
            return false;
        }

        $vowelCount = preg_match_all('/[aeiou]/', $local);

        if ($vowelCount === 0) {
            return true;
        }

        $digitCount = preg_match_all('/[0-9]/', $local);
        $hasSeparator = (bool) preg_match('/[._+\-]/', $local);

        return ! $hasSeparator && ($digitCount / $length) > 0.6;
    }
}
