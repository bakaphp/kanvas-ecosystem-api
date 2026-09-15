<?php

declare(strict_types=1);

namespace Kanvas\Guild\Customers\Services;

use Baka\Support\Str;
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
    /**
     * FCC keywords that are also ordinary words in a sales conversation. They are honored exactly
     * like the rest — the rule covers the whole set, and a customer who writes "cancel" meaning it
     * must be obeyed — but a hit is recorded as AMBIGUOUS_KEYWORD so the lead note asks a human to
     * confirm. Someone cancelling an appointment writes the same word as someone revoking consent,
     * and the opt-out is person-wide across every channel and lead.
     */
    private const array AMBIGUOUS_STOP_KEYWORDS = [
        'CANCEL',
        'END',
    ];

    private const array STOP_KEYWORDS = [
        'STOP',
        'STOPALL',
        'UNSUBSCRIBE',
        'QUIT',
        'REVOKE',
        'OPTOUT',
        ...self::AMBIGUOUS_STOP_KEYWORDS,
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

    /**
     * A revocation is a sentence, not a document. Anything past this is quoted thread or signature
     * that survived extractReason(), and it is bound for a custom field plus a lead note per lead.
     */
    public const int MAX_REASON_LENGTH = 500;

    public static function detect(?string $body): ?ConsentSignalEnum
    {
        $keyword = self::matchedKeyword($body);

        return $keyword !== null ? self::match($keyword) : null;
    }

    /**
     * The keyword that actually matched, so the signal and its tier are always read off the same
     * one. Scanning the candidates separately per question lets the two answers describe different
     * keywords.
     */
    private static function matchedKeyword(?string $body): ?string
    {
        foreach (self::candidates($body) as $candidate) {
            if (self::match($candidate) !== null) {
                return $candidate;
            }
        }

        return null;
    }

    public static function isStop(?string $body): bool
    {
        return self::detect($body) === ConsentSignalEnum::STOP;
    }

    /**
     * Whether a detected STOP came from a keyword that is also ordinary conversation. Callers use it
     * to pick the match tier — the stop is applied either way.
     */
    public static function stopKeywordIsAmbiguous(?string $body): bool
    {
        return in_array(self::matchedKeyword($body), self::AMBIGUOUS_STOP_KEYWORDS, true);
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

            return self::isSignatureDelimiter($line) ? null : $line;
        }

        return null;
    }

    /**
     * The part of an inbound message worth keeping as the reason an opt-out was applied.
     *
     * An email arrives with the person's own words on top and the whole prior thread quoted below,
     * so storing the raw body writes an entire correspondence — signatures, phone numbers,
     * everything ever discussed — into a custom field and a lead note, once per lead that person
     * has. It also buries the one sentence a human needs in order to review a phrase-tier
     * inference, which is the whole point of recording the reason.
     */
    public static function extractReason(?string $body, int $maxLength = self::MAX_REASON_LENGTH): ?string
    {
        $lines = [];

        foreach (preg_split('/\R/', trim((string) $body)) ?: [] as $line) {
            $line = trim($line);

            if (self::isSignatureDelimiter($line)) {
                break;
            }

            if ($line === '' || str_starts_with($line, '>')) {
                continue;
            }

            $lines[] = $line;
        }

        return Str::trimToNull(Str::limit(implode(' ', $lines), $maxLength));
    }

    private static function isSignatureDelimiter(string $line): bool
    {
        return preg_match('/^(--\s*$|__|sent from |on .+ wrote:)/i', $line) === 1;
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
