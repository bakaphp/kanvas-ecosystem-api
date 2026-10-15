<?php

declare(strict_types=1);

namespace Tests\Insurance\Fakes;

use Kanvas\Exceptions\ValidationException;
use Kanvas\Insurance\Contracts\CustomerDataProviderInterface;
use Kanvas\Insurance\Contracts\QuoteUpdateProviderInterface;
use Kanvas\Insurance\DataTransferObject\InsuranceCustomer;
use Kanvas\Insurance\DataTransferObject\InsuranceQuoteRequest;
use Kanvas\Insurance\DataTransferObject\QuoteResult;
use Kanvas\Insurance\Enums\InsuranceCustomerFieldEnum;

class FakeCustomerProvider extends FakeCanonicalProvider implements CustomerDataProviderInterface, QuoteUpdateProviderInterface
{
    public array $updates = [];

    public ?string $rejectCustomerWith = null;

    public function requiredCustomerFields(?InsuranceCustomer $customer = null): array
    {
        return [
            InsuranceCustomerFieldEnum::FIRST_NAME,
            InsuranceCustomerFieldEnum::BIRTH_DATE,
            InsuranceCustomerFieldEnum::DOCUMENT_NUMBER,
            InsuranceCustomerFieldEnum::SEX,
        ];
    }

    public function customerBlock(InsuranceCustomer $customer): array
    {
        return [
            'nombre' => $customer->get(InsuranceCustomerFieldEnum::FIRST_NAME),
            'numeroDocumento' => $customer->get(InsuranceCustomerFieldEnum::DOCUMENT_NUMBER),
        ];
    }

    public function updateQuote(string $quoteNumber, InsuranceQuoteRequest $request): QuoteResult
    {
        if ($this->rejectCustomerWith !== null) {
            throw new ValidationException($this->rejectCustomerWith);
        }

        $this->updates[] = [$quoteNumber, $request->payload];

        return $this->getQuote($quoteNumber);
    }
}
