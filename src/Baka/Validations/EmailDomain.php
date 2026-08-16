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
    public const SPAM_SEGMENT_MIN_LENGTH = 8;

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
     */
    public static function hasSpamLocalPart(string $email): bool
    {
        if (! str_contains($email, '@')) {
            return false;
        }

        $local = Str::lower(Str::before($email, '@'));

        if (strlen($local) < self::SPAM_LOCAL_MIN_LENGTH) {
            return false;
        }

        if (preg_match_all('/[aeiou]/', $local) === 0) {
            return true;
        }

        /**
         * Score each separator-delimited segment on its own. Scoring the whole
         * string let a single `_` disable the check — which is exactly how the
         * `dggie_l9pbrxc3ex@gmail.com` campaign got through.
         */
        foreach (preg_split('/[._+\-]+/', $local, -1, PREG_SPLIT_NO_EMPTY) as $segment) {
            if (self::isRandomSegment($segment)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Why the address looks like a bot signup, or null when it looks legitimate.
     * The reason is logged so a false positive traces to the rule that produced
     * it rather than being guessed at.
     */
    public static function spamReason(string $email, array $extraBlocked = []): ?string
    {
        if (self::isBlockedDomain($email, $extraBlocked)) {
            return 'blocked_domain';
        }

        if (EmailProvider::violatesProviderRules($email)) {
            return 'impossible_provider_address';
        }

        if (self::hasSpamLocalPart($email)) {
            return 'random_local_part';
        }

        return null;
    }

    private static function isRandomSegment(string $segment): bool
    {
        $length = strlen($segment);

        if ($length < self::SPAM_SEGMENT_MIN_LENGTH) {
            return false;
        }

        if (preg_match_all('/[aeiou]/', $segment) === 0) {
            return true;
        }

        if ((preg_match_all('/[0-9]/', $segment) / $length) > 0.6) {
            return true;
        }

        return self::interiorDigitRuns($segment) >= 2;
    }

    /**
     * Digit groups with a letter on both sides. Humans append a number
     * (`john1985`) or prefix one; generators sprinkle them through the string
     * (`l9pbrxc3ex`), so two or more interior groups is a machine signature.
     */
    private static function interiorDigitRuns(string $segment): int
    {
        preg_match_all('/(?<=[a-z])[0-9]+(?=[a-z])/', $segment, $matches);

        return count($matches[0]);
    }
}
