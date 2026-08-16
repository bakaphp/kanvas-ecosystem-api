<?php

declare(strict_types=1);

namespace Baka\Validations;

use Illuminate\Support\Str;

/**
 * Rejects addresses that cannot exist at the provider they claim.
 *
 * A farm signing up as `dggie_l9pbrxc3ex@gmail.com` never had a Gmail account —
 * Gmail usernames are letters, digits and dots only, so Google bounces every one
 * of them. That makes the provider's own syntax a far stronger signal than any
 * randomness heuristic: escaping it costs the attacker a real registered mailbox
 * per signup instead of a string generator.
 */
final class EmailProvider
{
    private const GMAIL_RULE = [
        'pattern' => '/^[a-z0-9]+(?:\.[a-z0-9]+)*$/',
        'max' => 30,
    ];

    private const GMAIL_DOMAINS = [
        'gmail.com',
        'googlemail.com',
    ];

    /**
     * Providers that treat `+tag` as a delivery alias of the base mailbox
     * rather than as part of the address.
     */
    private const PLUS_ADDRESSING_DOMAINS = [
        'gmail.com',
        'googlemail.com',
        'outlook.com',
        'hotmail.com',
        'live.com',
        'msn.com',
        'icloud.com',
        'me.com',
        'proton.me',
        'protonmail.com',
    ];

    /**
     * Charset + length the provider itself enforces at signup, keyed by domain.
     *
     * Only Gmail is encoded because its rule is unambiguous and publicly
     * documented. Add a provider here only once its rule is verified — a wrong
     * entry locks out real users, which is far worse than letting a bot through.
     *
     * Minimum length is deliberately absent: every provider grandfathered short
     * usernames in through acquisitions and Workspace migrations.
     */
    private const PROVIDER_RULES = [
        'gmail.com' => self::GMAIL_RULE,
        'googlemail.com' => self::GMAIL_RULE,
    ];

    public static function violatesProviderRules(string $email): bool
    {
        $domain = self::domain($email);
        $rule = self::PROVIDER_RULES[$domain] ?? null;

        if ($rule === null) {
            return false;
        }

        $local = self::stripPlusTag(self::local($email), $domain);

        if ($local === '' || strlen($local) > $rule['max']) {
            return true;
        }

        return preg_match($rule['pattern'], $local) !== 1;
    }

    /**
     * Collapse an address to the mailbox it actually delivers to, so
     * `a.n.a+one@gmail.com` and `ana+two@googlemail.com` land in one bucket.
     * Providers we have no alias rules for are only lowercased.
     */
    public static function canonicalize(string $email): string
    {
        $domain = self::domain($email);
        $local = self::stripPlusTag(self::local($email), $domain);

        if (in_array($domain, self::GMAIL_DOMAINS, true)) {
            return str_replace('.', '', $local) . '@gmail.com';
        }

        return $local . '@' . $domain;
    }

    private static function stripPlusTag(string $local, string $domain): string
    {
        if (! in_array($domain, self::PLUS_ADDRESSING_DOMAINS, true)) {
            return $local;
        }

        return Str::before($local, '+');
    }

    private static function local(string $email): string
    {
        return Str::lower(trim(Str::beforeLast($email, '@')));
    }

    private static function domain(string $email): string
    {
        if (! str_contains($email, '@')) {
            return '';
        }

        return Str::lower(trim(Str::afterLast($email, '@')));
    }
}
