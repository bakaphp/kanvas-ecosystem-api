<?php

declare(strict_types=1);

namespace Kanvas\Insurance\Contracts;

use Kanvas\Insurance\DataTransferObject\InsuranceCustomer;

interface CustomerDataProviderInterface
{
    public function requiredCustomerFields(?InsuranceCustomer $customer = null): array;

    public function customerBlock(InsuranceCustomer $customer): array;
}
