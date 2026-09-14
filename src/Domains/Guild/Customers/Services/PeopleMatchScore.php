<?php

declare(strict_types=1);

namespace Kanvas\Guild\Customers\Services;

use Baka\Support\Str;
use Kanvas\Guild\Customers\Models\People;

/**
 * Ratio of matched search terms over the terms the caller supplied. Names match fuzzily
 * because CRMs spell people differently; phones and emails match exactly because a
 * near-miss there is a different person, not a typo.
 */
final class PeopleMatchScore
{
    private const float NAME_SIMILARITY_THRESHOLD = 80.0;

    /**
     * Clients render value * 100, so 0.0 would show "0% Match" — "definitely wrong" — for a
     * candidate nothing was scored against.
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
        $firstname = Str::trimToNull($firstname);
        $lastname = Str::trimToNull($lastname);
        $phones = self::cleanTerms($phones);
        $emails = self::cleanTerms($emails);

        $total = 0;
        $matched = 0;

        if ($firstname !== null) {
            $total++;
            $matched += self::namesMatch($firstname, $people->firstname) ? 1 : 0;
        }

        if ($lastname !== null) {
            $total++;
            $matched += self::namesMatch($lastname, $people->lastname) ? 1 : 0;
        }

        if ($phones !== []) {
            $peoplePhones = $people->getAllPhones()
                ->pluck('value')
                ->map(fn ($value) => Str::digitsOnly((string) $value))
                ->all();

            foreach ($phones as $phone) {
                $total++;
                $matched += in_array(Str::digitsOnly($phone), $peoplePhones, true) ? 1 : 0;
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
     * @param array<int, mixed> $terms
     *
     * @return array<int, string>
     */
    private static function cleanTerms(array $terms): array
    {
        return array_filter(
            array_map(fn (mixed $term) => is_scalar($term) ? Str::trimToNull((string) $term) : null, $terms),
            fn (?string $term) => $term !== null
        );
    }

    private static function namesMatch(string $searchName, ?string $peopleName): bool
    {
        if ($peopleName === null || $peopleName === '') {
            return false;
        }

        similar_text(
            strtolower($searchName),
            strtolower(trim($peopleName)),
            $percent
        );

        return $percent >= self::NAME_SIMILARITY_THRESHOLD;
    }
}
