<?php

declare(strict_types=1);

namespace Kanvas\Insurance\DataTransferObject;

use Kanvas\Exceptions\ValidationException;
use Kanvas\Insurance\Contracts\CustomerDataProviderInterface;
use Kanvas\Insurance\Contracts\InsuranceProviderInterface;
use Kanvas\Insurance\Enums\InsurancePaymentTermEnum;
use Kanvas\Inventory\Variants\Models\Variants;

class InsuranceOrderData
{
    public function __construct(
        public readonly ?string $provider,
        public readonly ?string $productCode,
        public readonly ?string $quoteNumber,
        public readonly ?Variants $vehicle,
        public readonly ?Variants $plan,
        public readonly ?InsuranceCustomer $customer,
        public readonly array $legacyPayload = [],
        public readonly array $legacyCliente = [],
        public readonly bool $legacy = false,
        public readonly bool $legacyComplete = false,
        public readonly ?InsurancePaymentTermEnum $paymentTerm = null,
    ) {
    }

    public function hasCustomerData(): bool
    {
        return $this->customer !== null || $this->legacyCliente !== [];
    }

    public function isIdentified(): bool
    {
        return $this->provider !== null && $this->quoteNumber !== null;
    }

    public function quoteRequest(InsuranceProviderInterface $provider): InsuranceQuoteRequest
    {
        if ($this->productCode === null) {
            throw new ValidationException('Order carries no insurance product to quote.');
        }

        $payload = $this->legacyPayload;
        $cliente = $this->clienteFor($provider);

        if ($cliente !== []) {
            $payload['cliente'] = $cliente;
        }

        return new InsuranceQuoteRequest(
            product: $this->productCode,
            payload: $payload,
            vehicle: $this->vehicle,
            rebuildVehicle: ! $this->legacy,
            paymentTerm: $this->paymentTerm,
        );
    }

    private function clienteFor(InsuranceProviderInterface $provider): array
    {
        if ($this->legacyCliente !== []) {
            return $this->legacyCliente;
        }

        if ($this->customer === null || ! $provider instanceof CustomerDataProviderInterface) {
            return [];
        }

        return $provider->customerBlock($this->customer);
    }
}
