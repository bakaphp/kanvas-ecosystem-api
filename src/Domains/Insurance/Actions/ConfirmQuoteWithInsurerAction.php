<?php

declare(strict_types=1);

namespace Kanvas\Insurance\Actions;

use Kanvas\Exceptions\ValidationException;
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
    ) {
    }

    public function execute(): QuoteResult
    {
        $quote = new PushCustomerToQuoteAction($this->provider, $this->quoteNumber, $this->update)->execute();

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
}
