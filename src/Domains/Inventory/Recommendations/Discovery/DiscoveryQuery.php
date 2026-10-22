<?php

declare(strict_types=1);

namespace Kanvas\Inventory\Recommendations\Discovery;

use Kanvas\Inventory\Recommendations\Discovery\Constraints\EqualsConstraint;
use Kanvas\Inventory\Recommendations\Discovery\Constraints\InConstraint;
use Kanvas\Inventory\Recommendations\Discovery\Constraints\RangeConstraint;
use Kanvas\Inventory\Recommendations\Services\IntentLexiconService;

/**
 * Everything an engine needs, in no engine's syntax. Adapters only translate;
 * tenant scope, the unknown-price policy and which index field a bound lands on
 * were all decided while building this.
 */
final readonly class DiscoveryQuery
{
    /**
     * @param list<string> $optionalWords words already present in $text that may boost but are not required
     * @param list<string> $alternatives extra phrasings, each searched and merged by rank
     * @param list<EqualsConstraint|InConstraint|RangeConstraint> $constraints
     * @param string $vectorText the shopper's own words, for engines that search by meaning
     */
    public function __construct(
        public string $text,
        public array $optionalWords = [],
        public array $alternatives = [],
        public array $constraints = [],
        public string $vectorText = '',
    ) {
    }

    /**
     * @return list<string>
     */
    public function texts(): array
    {
        return array_values(array_unique([$this->text, ...$this->alternatives]));
    }

    /**
     * Stable across equal queries and different for any change that could change
     * the result, so it can key a cache.
     */
    public function signature(): string
    {
        $constraints = array_map(
            static fn (EqualsConstraint|InConstraint|RangeConstraint $constraint): array => [
                $constraint::class,
                get_object_vars($constraint),
            ],
            $this->constraints,
        );

        $normalize = static fn (string $text): string => IntentLexiconService::normalize($text);

        // Casing and accents must not miss the cache: gift-finder traffic repeats
        // the same request typed a dozen ways.
        return md5((string) json_encode([
            $normalize($this->text),
            array_map($normalize, $this->optionalWords),
            array_map($normalize, $this->alternatives),
            $constraints,
            $normalize($this->vectorText),
        ]));
    }
}
