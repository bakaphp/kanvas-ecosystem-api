<?php

declare(strict_types=1);

namespace Kanvas\Inventory\Recommendations\Services;

use Baka\Contracts\AppInterface;
use Kanvas\Inventory\Recommendations\Enums\ConfigurationEnum;

/**
 * SQL fallback only — an engine tokenizes on its own. Without this a LIKE on the
 * raw sentence matches nothing: no product is named "un regalo para mi esposo".
 */
class SearchTermTokenizerService
{
    private const int MIN_TERM_LENGTH = 3;

    /** @var list<string>|null */
    private ?array $stopWords = null;

    public function __construct(
        private readonly AppInterface $app,
    ) {
    }

    /**
     * @return list<string>
     */
    public function tokenize(string $sentence): array
    {
        $normalized = IntentLexiconService::normalize($sentence);
        $words = preg_split('/[^\p{L}\p{N}]+/u', $normalized, -1, PREG_SPLIT_NO_EMPTY);

        if ($words === false) {
            return [];
        }

        $stopWords = $this->stopWords();

        $terms = array_filter(
            $words,
            static fn (string $word): bool => mb_strlen($word) >= self::MIN_TERM_LENGTH
                && ! in_array($word, $stopWords, true)
                && ! is_numeric($word),
        );

        return array_values(array_unique($terms));
    }

    /**
     * @return list<string>
     */
    private function stopWords(): array
    {
        if ($this->stopWords !== null) {
            return $this->stopWords;
        }

        $merged = [
            ...(array) config('inventory-discovery.stop_words', []),
            ...ConfigurationEnum::STOP_WORDS->listFrom($this->app),
        ];

        return $this->stopWords = array_values(array_unique(array_map(
            static fn (mixed $word): string => is_string($word) ? IntentLexiconService::normalize($word) : '',
            $merged,
        )));
    }
}
