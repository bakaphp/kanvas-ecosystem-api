<?php

declare(strict_types=1);

namespace Tests\Insurance\Fakes;

use Kanvas\Insurance\Contracts\FreshQuoteProviderInterface;
use Kanvas\Insurance\DataTransferObject\QuoteResult;

class FakeFreshQuoteProvider extends FakeCustomerProvider implements FreshQuoteProviderInterface
{
    public array $fresh = [];

    public bool $freshSucceeds = true;

    public function freshQuote(string $quoteNumber, string $productCode, string $requestId): QuoteResult
    {
        $this->fresh[] = [$quoteNumber, $productCode, $requestId];

        return new QuoteResult(
            success: $this->freshSucceeds,
            message: 'ok',
            quoteNumber: 'FRESH-' . $quoteNumber,
            total: $this->total,
            productCode: $productCode,
        );
    }
}
