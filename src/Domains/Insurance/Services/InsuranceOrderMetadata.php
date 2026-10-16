<?php

declare(strict_types=1);

namespace Kanvas\Insurance\Services;

use Kanvas\Insurance\DataTransferObject\InsuranceCustomer;

class InsuranceOrderMetadata
{
    public const string QUOTE_NUMBER = 'quote_number';

    public const string CUSTOMER = 'customer';

    public static function apply(mixed $metadata, string $quoteNumber, ?InsuranceCustomer $customer): array
    {
        $metadata = is_array($metadata) ? $metadata : [];
        $data = is_array($metadata['data'] ?? null) ? $metadata['data'] : [];

        $data[self::QUOTE_NUMBER] = $quoteNumber;

        if ($customer !== null) {
            $data[self::CUSTOMER] = $customer->toArray();
        }

        $metadata['data'] = $data;

        return $metadata;
    }
}
