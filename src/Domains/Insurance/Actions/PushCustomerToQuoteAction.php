<?php

declare(strict_types=1);

namespace Kanvas\Insurance\Actions;

use Kanvas\Insurance\Contracts\InsuranceProviderInterface;
use Kanvas\Insurance\Contracts\QuoteUpdateProviderInterface;
use Kanvas\Insurance\DataTransferObject\InsuranceQuoteRequest;
use Kanvas\Insurance\DataTransferObject\QuoteResult;

class PushCustomerToQuoteAction
{
    public function __construct(
        private readonly InsuranceProviderInterface $provider,
        private readonly string $quoteNumber,
        private readonly ?InsuranceQuoteRequest $update = null,
    ) {
    }

    public function execute(): QuoteResult
    {
        if ($this->update !== null && $this->provider instanceof QuoteUpdateProviderInterface) {
            $this->provider->updateQuote($this->quoteNumber, $this->update);
        }

        return $this->provider->getQuote($this->quoteNumber);
    }
}
