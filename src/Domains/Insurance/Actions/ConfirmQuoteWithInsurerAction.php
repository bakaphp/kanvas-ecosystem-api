<?php

declare(strict_types=1);

namespace Kanvas\Insurance\Actions;

use Kanvas\Exceptions\ValidationException;
use Kanvas\Insurance\Contracts\FreshQuoteProviderInterface;
use Kanvas\Insurance\Contracts\InsuranceProviderInterface;
use Kanvas\Insurance\DataTransferObject\InsuranceQuoteRequest;
use Kanvas\Insurance\DataTransferObject\QuoteResult;

class ConfirmQuoteWithInsurerAction
{
    public function __construct(
        private readonly InsuranceProviderInterface $provider,
        private readonly string $quoteNumber,
        private readonly string $productCode,
        private readonly ?InsuranceQuoteRequest $update = null,
        private readonly ?string $requestId = null,
    ) {
    }

    public function execute(): QuoteResult
    {
        $quote = new PushCustomerToQuoteAction($this->provider, $this->workingQuoteNumber(), $this->update)->execute();

        if (! $quote->success) {
            throw new ValidationException('The quote is no longer valid');
        }

        if ($quote->productCode !== null && $quote->productCode !== $this->productCode) {
            throw new ValidationException('This quote was issued for a different plan');
        }

        if (($quote->total ?? 0) <= 0) {
            throw new ValidationException('This quote carries no price');
        }

        return $quote;
    }

    private function workingQuoteNumber(): string
    {
        if ($this->requestId === null || ! $this->provider instanceof FreshQuoteProviderInterface) {
            return $this->quoteNumber;
        }

        $fresh = $this->provider->freshQuote($this->quoteNumber, $this->productCode, $this->requestId);

        if (! $fresh->success) {
            throw new ValidationException('The quote is no longer valid');
        }

        return $fresh->quoteNumber;
    }
}
