<?php

declare(strict_types=1);

namespace Kanvas\Insurance\Contracts;

use Kanvas\Insurance\DataTransferObject\QuoteResult;

interface FreshQuoteProviderInterface
{
    public function freshQuote(string $quoteNumber, string $productCode, string $requestId): QuoteResult;
}
