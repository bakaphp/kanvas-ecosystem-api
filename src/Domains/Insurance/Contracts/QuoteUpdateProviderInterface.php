<?php

declare(strict_types=1);

namespace Kanvas\Insurance\Contracts;

use Kanvas\Insurance\DataTransferObject\InsuranceQuoteRequest;
use Kanvas\Insurance\DataTransferObject\QuoteResult;

interface QuoteUpdateProviderInterface
{
    public function updateQuote(string $quoteNumber, InsuranceQuoteRequest $request): QuoteResult;
}
