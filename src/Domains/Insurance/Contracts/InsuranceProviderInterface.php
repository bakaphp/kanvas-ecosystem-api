<?php

declare(strict_types=1);

namespace Kanvas\Insurance\Contracts;

use Kanvas\Insurance\DataTransferObject\InsuranceQuoteRequest;
use Kanvas\Insurance\DataTransferObject\QuoteResult;
use Kanvas\Workflow\Enums\IntegrationsEnum;

interface InsuranceProviderInterface
{
    public function name(): string;

    public function integration(): IntegrationsEnum;

    public function quote(InsuranceQuoteRequest $request): QuoteResult;

    public function getQuote(string $quoteNumber): QuoteResult;
}
