<?php

declare(strict_types=1);

namespace Kanvas\Guild\Customers\Services;

use Kanvas\Guild\Customers\Enums\ConsentSignalEnum;

/**
 * The one place that decides whether an inbound message is a consent signal.
 *
 * The keyword set is the FCC's (47 CFR 64.1200, effective 2025-04-11), which is also Twilio's
 * default. REVOKE and OPTOUT arrived with that rule — a matcher without them is out of date.
 *
 * Matching is whole-value only, never substring: "stop by the dealership tomorrow" is not an
 * opt-out, and treating it as one silences a live customer. The cost of that strictness is that
 * "please stop texting me" does not match either — which is deliberate. The FCC requires honoring
 * a revocation made in "any reasonable manner", and Twilio explicitly does NOT block non-keyword
 * phrasing on our behalf, so that half of the obligation belongs to the agent's stop_contact tool.
 * This service is the deterministic floor, not the whole of compliance.
 */
final class ConsentKeywordService
{
    private const array STOP_KEYWORDS = [
        'STOP',
        'STOPALL',
        'UNSUBSCRIBE',
        'CANCEL',
        'END',
        'QUIT',
        'REVOKE',
        'OPTOUT',
    ];

    private const array START_KEYWORDS = [
        'START',
        'UNSTOP',
        'YES',
    ];

    private const array HELP_KEYWORDS = [
        'HELP',
        'INFO',
    ];

    /**
     * Do-not-contact intent written as a sentence rather than a keyword. Matched inside a longer
     * message, so this tier is fuzzier than the keyword one and callers must honor the qualifier
     * carve-out below before acting on it.
     */
    private const array NO_CONTACT_PHRASES = [
        'do not contact',
        'dont contact',
        'do not reach out',
        'dont reach out',
        'do not reachout',
        'dont reachout',
        'do not call',
        'dont call',
        'do not text',
        'dont text',
        'do not email',
        'dont email',
        'do not message',
        'dont message',
        'no contact',
        'no llamar',
        'no contactar',
        'no me escribas',
        'no me llamen',
        'stop contacting',
        'stop reaching out',
        'stop texting',
        'stop emailing',
        'stop messaging',
        'take me off',
        'remove me',
        'unsubscribe',
        'dnc',
    ];

    /**
     * A phrase hit plus one of these is a NARROWED request, not a revocation: "text me instead" is a
     * channel preference and "don't call before 5pm" is a time window. Opting either of them out of
     * everything silences a customer who was still talking to us.
     */
    private const array SCOPE_QUALIFIERS = [
        'instead',
        'rather than',
        'use my',
        'try my',
        'en vez',
        'mejor',
        'before',
        'after',
        'until',
        'during',
        'between',
        'this week',
        'next week',
        'tomorrow',
        'tonight',
        'morning',
        'afternoon',
        'evening',
        'weekend',
        'antes de',
        'despues de',
    ];

    /**
     * Long bodies can never match — an exact comparison against a 4-letter keyword fails the moment
     * there is a fifth letter — so cap before doing regex work on an arbitrarily large email.
     */
    private const int MAX_CANDIDATE_LENGTH = 2048;

    /**
     * Phrase scanning needs a far looser cap than exact matching: a keyword comparison fails on a
     * long body by definition, but "please remove me from your list" can sit anywhere inside one.
     */
    private const int MAX_PHRASE_SCAN_LENGTH = 16384;

    public static function detect(?string $body): ?ConsentSignalEnum
    {
        foreach (self::candidates($body) as $candidate) {
            $signal = self::match($candidate);

            if ($signal !== null) {
                return $signal;
            }
        }

        return null;
    }

    public static function isStop(?string $body): bool
    {
        return self::detect($body) === ConsentSignalEnum::STOP;
    }

    /**
     * Tier two: do-not-contact intent phrased as a sentence. Deliberately separate from detect() —
     * a keyword match is unambiguous and can act on its own, a phrase match cannot until the caller
     * has checked narrowsRequestScope().
     */
    public static function matchesNoContactPhrase(?string $text): bool
    {
        return self::containsAny($text, self::NO_CONTACT_PHRASES);
    }

    /**
     * True when the message narrows what it is asking for — a different channel, or a time window.
     * "Don't email me, text me instead" and "don't call before 5pm" both trip a no-contact phrase
     * while asking us to keep talking, so they need a human rather than a blanket opt-out.
     */
    public static function narrowsRequestScope(?string $text): bool
    {
        return self::containsAny($text, self::SCOPE_QUALIFIERS);
    }

    private static function containsAny(?string $text, array $needles): bool
    {
        $normalized = self::normalizeWords($text);

        if ($normalized === '') {
            return false;
        }

        foreach ($needles as $needle) {
            // Word boundaries so "dnc" cannot fire inside "abcdncxyz" and "no contact" cannot match
            // part of a longer unrelated token.
            if (preg_match('/\b' . preg_quote($needle, '/') . '\b/', $normalized) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * Lowercase, drop apostrophes so "don't" reads as "dont", and collapse every other non-alphanumeric
     * run to one space so "do-not-contact" and "do not contact" are the same string.
     */
    private static function normalizeWords(?string $text): string
    {
        $text = trim((string) $text);

        if ($text === '' || mb_strlen($text) > self::MAX_PHRASE_SCAN_LENGTH) {
            return '';
        }

        $normalized = str_replace(["'", '’', '`'], '', mb_strtolower($text));
        $normalized = (string) preg_replace('/[^a-z0-9]+/', ' ', $normalized);

        return trim((string) preg_replace('/\s+/', ' ', $normalized));
    }

    private static function match(string $candidate): ?ConsentSignalEnum
    {
        return match (true) {
            in_array($candidate, self::STOP_KEYWORDS, true) => ConsentSignalEnum::STOP,
            in_array($candidate, self::START_KEYWORDS, true) => ConsentSignalEnum::START,
            in_array($candidate, self::HELP_KEYWORDS, true) => ConsentSignalEnum::HELP,
            default => null,
        };
    }

    /**
     * Two shots at a match. The whole body covers SMS and WhatsApp, where a one-word reply IS the
     * whole message. The first meaningful line covers email, where "Unsubscribe" arrives on line 1
     * above a signature and a quoted thread and would never match as a whole body.
     *
     * @return string[]
     */
    private static function candidates(?string $body): array
    {
        $body = trim((string) $body);

        if ($body === '' || mb_strlen($body) > self::MAX_CANDIDATE_LENGTH) {
            return [];
        }

        $candidates = [self::normalize($body)];

        $firstLine = self::firstMeaningfulLine($body);

        if ($firstLine !== null) {
            $candidates[] = self::normalize($firstLine);
        }

        return array_values(array_filter(array_unique($candidates)));
    }

    /**
     * Drop quoted reply lines and stop at a signature delimiter, then take the first line with
     * content. Without this every email opt-out is buried under "Sent from my iPhone".
     */
    private static function firstMeaningfulLine(string $body): ?string
    {
        foreach (preg_split('/\R/', $body) ?: [] as $line) {
            $line = trim($line);

            if ($line === '' || str_starts_with($line, '>')) {
                continue;
            }

            if (preg_match('/^(--\s*$|__|sent from |on .+ wrote:)/i', $line) === 1) {
                return null;
            }

            return $line;
        }

        return null;
    }

    /**
     * Fold away everything that is not a letter, so "Stop.", "STOP!", "opt-out" and "Opt Out" all
     * land on the same token. Non-letters can only ever be decoration around a keyword; none of the
     * keywords contain one.
     */
    private static function normalize(string $value): string
    {
        return (string) preg_replace('/[^A-Z]/', '', mb_strtoupper(trim($value)));
    }
}
