<?php

declare(strict_types=1);

namespace Kanvas\Guild\Customers\Services;

use Kanvas\Guild\Customers\Models\People;

/**
 * How well a People record matches a set of inbound search terms: the ratio of
 * matched terms over the terms the caller actually supplied.
 *
 * Used to rank CRM pull candidates so the client can show a "% Match" and sort
 * the picker. Names match fuzzily — people are typed differently across systems —
 * while phones and emails match exactly, because a near-miss there is a different
 * person, not a typo.
 *
 * It carries matchedTerms/totalTerms alongside the ratio so a caller can tell
 * "0.5 because one of two terms missed" apart from "0.5" as an opaque number,
 * and so the no-terms floor is a state you can ask about rather than a magic value.
 */
final class PeopleMatchScore
{
    /**
     * A name scoring at or above this percent counts as a match. Below it the
     * strings are different enough that treating them as the same person is a guess.
     */
    private const float NAME_SIMILARITY_THRESHOLD = 80.0;

    /**
     * Scored against nothing is not the same as scored and failed. The client
     * renders value * 100, so 0.0 would draw "0% Match" — reads as "definitely
     * wrong" where the truth is "unknown".
     */
    private const float NO_TERMS_VALUE = 0.1;

    private function __construct(
        public readonly float $value,
        public readonly int $matchedTerms,
        public readonly int $totalTerms,
    ) {
    }

    /**
     * @param array<int, string|null> $phones
     * @param array<int, string|null> $emails
     */
    public static function for(
        People $people,
        ?string $firstname = null,
        ?string $lastname = null,
        array $phones = [],
        array $emails = [],
    ): self {
        $phones = self::cleanTerms($phones);
        $emails = self::cleanTerms($emails);

        $total = 0;
        $matched = 0;

        if ($firstname !== null && $firstname !== '') {
            $total++;
            $matched += self::namesMatch($firstname, $people->firstname) ? 1 : 0;
        }

        if ($lastname !== null && $lastname !== '') {
            $total++;
            $matched += self::namesMatch($lastname, $people->lastname) ? 1 : 0;
        }

        if ($phones !== []) {
            $peoplePhones = $people->getAllPhones()
                ->pluck('value')
                ->map(fn ($value) => self::digits((string) $value))
                ->all();

            foreach ($phones as $phone) {
                $total++;
                $matched += in_array(self::digits($phone), $peoplePhones, true) ? 1 : 0;
            }
        }

        if ($emails !== []) {
            $peopleEmails = $people->getEmails()
                ->pluck('value')
                ->map(fn ($value) => strtolower(trim((string) $value)))
                ->all();

            foreach ($emails as $email) {
                $total++;
                $matched += in_array(strtolower($email), $peopleEmails, true) ? 1 : 0;
            }
        }

        if ($total === 0) {
            return new self(self::NO_TERMS_VALUE, 0, 0);
        }

        return new self(round($matched / $total, 2), $matched, $total);
    }

    public function isUnscored(): bool
    {
        return $this->totalTerms === 0;
    }

    /**
     * @param array<int, string|null> $terms
     *
     * @return array<int, string>
     */
    private static function cleanTerms(array $terms): array
    {
        return array_filter(array_map('trim', array_filter($terms, 'is_string')));
    }

    private static function digits(string $value): string
    {
        return (string) preg_replace('/\D/', '', $value);
    }

    private static function namesMatch(string $searchName, ?string $peopleName): bool
    {
        if ($peopleName === null || $peopleName === '') {
            return false;
        }

        similar_text(
            strtolower(trim($searchName)),
            strtolower(trim($peopleName)),
            $percent
        );

        return $percent >= self::NAME_SIMILARITY_THRESHOLD;
    }
}
