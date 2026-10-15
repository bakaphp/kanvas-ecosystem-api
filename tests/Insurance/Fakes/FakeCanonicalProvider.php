<?php

declare(strict_types=1);

namespace Tests\Insurance\Fakes;

use Closure;
use Kanvas\Insurance\Contracts\CanonicalQuoteProviderInterface;
use Kanvas\Insurance\DataTransferObject\CanonicalQuoteInput;
use Kanvas\Insurance\DataTransferObject\InsuranceQuoteRequest;
use Kanvas\Insurance\DataTransferObject\QuoteResult;
use Kanvas\Workflow\Enums\IntegrationsEnum;

class FakeCanonicalProvider implements CanonicalQuoteProviderInterface
{
    public array $calls = [];

    public int $reads = 0;

    public float $total = 116.0;

    public function __construct(
        private readonly string $name,
        private readonly Closure $respond,
        private readonly bool $needsVehicleModelId = false,
        private readonly ?string $productCode = null,
    ) {
    }

    public function name(): string
    {
        return $this->name;
    }

    public function integration(): IntegrationsEnum
    {
        return IntegrationsEnum::UNIVERSAL_SEGUROS;
    }

    public function quote(InsuranceQuoteRequest $request): QuoteResult
    {
        return $this->getQuote('FAKE');
    }

    public function getQuote(string $quoteNumber): QuoteResult
    {
        $this->reads++;

        return new QuoteResult(
            success: true,
            message: 'ok',
            quoteNumber: $quoteNumber,
            premium: $this->total / 1.16,
            tax: $this->total - $this->total / 1.16,
            total: $this->total,
            currency: 'DOP',
            commission: 9.0,
            canEmit: true,
            productCode: $this->productCode,
        );
    }

    public function requiresVehicleModelId(): bool
    {
        return $this->needsVehicleModelId;
    }

    public function quoteCanonical(
        CanonicalQuoteInput $input,
        string $planCode,
        ?string $vehicleModelId
    ): QuoteResult {
        $this->calls[] = ['plan' => $planCode, 'vehicleModelId' => $vehicleModelId];

        return ($this->respond)($planCode, count($this->calls));
    }
}
