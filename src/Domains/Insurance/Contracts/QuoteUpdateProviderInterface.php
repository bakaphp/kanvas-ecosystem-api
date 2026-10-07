<?php

declare(strict_types=1);

namespace Kanvas\Insurance\Contracts;

use Kanvas\Insurance\DataTransferObject\InsuranceQuoteRequest;
use Kanvas\Insurance\DataTransferObject\QuoteResult;

/**
 * Opt-in: an insurer that validates the customer on an existing quote before it
 * can be paid and emitted, so the customer's data has to reach it after the quote
 * was compared and before the order is charged.
 */
interface QuoteUpdateProviderInterface
{
    public function updateQuote(string $quoteNumber, InsuranceQuoteRequest $request): QuoteResult;
}
