<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Bills\Contracts;

/**
 * Picks an expense account for a bill nothing else could code.
 */
interface ExpenseAccountSuggesterInterface
{
    /**
     * @param list<string> $lineDescriptions
     * @param list<array{account_number: string, name: string}> $accounts the company's expense accounts
     * @return array{account_number: ?string, confidence: float, reasoning: ?string}
     */
    public function suggest(string $vendorName, array $lineDescriptions, array $accounts): array;
}
