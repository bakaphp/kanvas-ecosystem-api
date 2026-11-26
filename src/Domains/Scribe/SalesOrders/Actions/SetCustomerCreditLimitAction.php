<?php

declare(strict_types=1);

namespace Kanvas\Scribe\SalesOrders\Actions;

use Kanvas\Exceptions\ValidationException;
use Kanvas\Guild\Organizations\Models\Organization;
use Kanvas\Scribe\SalesOrders\Enums\CreditConfigurationEnum;

/**
 * Sets the credit limit of a customer, in the currency it is stated in, or removes it so no limit is checked.
 */
class SetCustomerCreditLimitAction
{
    public function __construct(
        protected readonly Organization $customer,
        protected readonly ?float $amount,
        protected readonly ?string $currency,
    ) {
    }

    public function execute(): Organization
    {
        if ($this->amount === null) {
            $this->customer->del(CreditConfigurationEnum::CREDIT_LIMIT_AMOUNT->value);
            $this->customer->del(CreditConfigurationEnum::CREDIT_LIMIT_CURRENCY->value);

            return $this->customer;
        }

        $currency = strtoupper(trim((string) $this->currency));

        if ($this->amount < 0 || strlen($currency) !== 3) {
            throw new ValidationException('A credit limit needs an amount of zero or more and a three-letter currency.');
        }

        $this->customer->set(CreditConfigurationEnum::CREDIT_LIMIT_AMOUNT->value, (string) $this->amount);
        $this->customer->set(CreditConfigurationEnum::CREDIT_LIMIT_CURRENCY->value, $currency);

        return $this->customer;
    }
}
