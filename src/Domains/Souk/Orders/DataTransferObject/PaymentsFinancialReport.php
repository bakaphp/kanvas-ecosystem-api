<?php

declare(strict_types=1);

namespace Kanvas\Souk\Orders\DataTransferObject;

use Kanvas\Apps\Models\Apps;
use Kanvas\Inventory\Variants\Models\Variants;
use Kanvas\Souk\Payments\Enums\PaymentChannelEnum;
use Kanvas\Users\Models\Users;
use Spatie\LaravelData\Data;

class PaymentsFinancialReport extends Data
{
    public function __construct(
        public readonly Apps $app,
        public readonly Users $user,
        public readonly string $startDate,
        public readonly string $endDate,
        public readonly string $timezone = 'UTC',
        public readonly array $paidStates = ['paid'],
        public readonly array $orderTypeNames = [],
        public readonly ?Variants $variant = null,
        public readonly ?PaymentChannelEnum $channel = null,
        public readonly ?string $userEmail = null,
        public readonly array $providerCompanyIds = [],
        public readonly array $metadata = [],
    ) {
    }

    public static function fromMultiple(
        Apps $app,
        Users $user,
        array $providerCompanyIds,
        array $input,
        array $metadata,
    ): self {
        $variant = isset($input['variantId'])
            ? Variants::getById((int) $input['variantId'], $app)
            : null;

        $channel = isset($input['paymentChannel']) && strtoupper((string) $input['paymentChannel']) !== 'ALL'
            ? PaymentChannelEnum::from(strtolower((string) $input['paymentChannel']))
            : null;

        return new self(
            app: $app,
            user: $user,
            startDate: (string) $input['startDate'],
            endDate: (string) $input['endDate'],
            timezone: $input['timezone'] ?? 'UTC',
            paidStates: $input['paidStates'] ?? ['paid'],
            orderTypeNames: $input['orderTypeNames'] ?? [],
            variant: $variant,
            channel: $channel,
            userEmail: $input['user_email'] ?? null,
            providerCompanyIds: $providerCompanyIds,
            metadata: $metadata,
        );
    }
}
