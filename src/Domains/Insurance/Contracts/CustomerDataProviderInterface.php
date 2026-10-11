<?php

declare(strict_types=1);

namespace Kanvas\Insurance\Contracts;

use Kanvas\Insurance\DataTransferObject\InsuranceCustomer;
use Kanvas\Insurance\Enums\InsuranceCustomerFieldEnum;

interface CustomerDataProviderInterface
{
    public function requiredCustomerFields(): array;

    public function customerBlock(InsuranceCustomer $customer): array;
}
