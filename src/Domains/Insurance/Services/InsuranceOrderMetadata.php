<?php

declare(strict_types=1);

namespace Kanvas\Insurance\Services;

use Kanvas\Insurance\DataTransferObject\InsuranceCustomer;
use Kanvas\Insurance\Enums\InsurancePaymentTermEnum;

class InsuranceOrderMetadata
{
    public const string QUOTE_NUMBER = 'quote_number';

    public const string CUSTOMER = 'customer';

    public const string PAYMENT_TERM = 'payment_term';

    public const string START_AT = 'start_at';

    public const string END_AT = 'end_at';

    public static function apply(
        mixed $metadata,
        string $quoteNumber,
        ?InsuranceCustomer $customer,
        ?InsurancePaymentTermEnum $paymentTerm = null
    ): array {
        $metadata = is_array($metadata) ? $metadata : [];
        $data = is_array($metadata['data'] ?? null) ? $metadata['data'] : [];

        $data[self::QUOTE_NUMBER] = $quoteNumber;

        if ($customer !== null) {
            $data[self::CUSTOMER] = $customer->toArray();
        }

        if ($paymentTerm !== null) {
            $data[self::PAYMENT_TERM] = $paymentTerm->value;
        }

        $metadata['data'] = $data;

        return $metadata;
    }

    public static function withPolicyDates(mixed $metadata, ?string $startAt, ?string $endAt): array
    {
        $metadata = is_array($metadata) ? $metadata : [];
        $data = is_array($metadata['data'] ?? null) ? $metadata['data'] : [];

        if ($startAt !== null) {
            $data[self::START_AT] = $startAt;
        }

        if ($endAt !== null) {
            $data[self::END_AT] = $endAt;
        }

        $metadata['data'] = $data;

        return $metadata;
    }
}
