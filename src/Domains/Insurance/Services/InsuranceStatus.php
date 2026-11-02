<?php

declare(strict_types=1);

namespace Kanvas\Insurance\Services;

use Kanvas\Insurance\Enums\InsuranceCustomFieldEnum;
use Kanvas\Insurance\Enums\InsuranceOrderStatusEnum;
use Kanvas\Insurance\Enums\InsuranceOrderTypeEnum;
use Kanvas\Insurance\Enums\InsuranceStatusEnum;
use Kanvas\Souk\Orders\Models\Order;

final class InsuranceStatus
{
    public static function of(Order $order): ?InsuranceStatusEnum
    {
        if (! InsuranceOrderTypeEnum::INSURANCE->isTypeOf($order)) {
            return InsuranceStatusEnum::tryFrom((string) $order->get(InsuranceCustomFieldEnum::STATUS->value));
        }

        $slug = $order->orderStatus?->slug;

        return $slug === null ? null : InsuranceOrderStatusEnum::tryFrom($slug)?->toInsuranceStatus();
    }

    public static function hasQuote(Order $order): bool
    {
        if ((string) $order->get(InsuranceCustomFieldEnum::QUOTE_NUMBER->value) !== '') {
            return true;
        }

        return ! InsuranceOrderTypeEnum::INSURANCE->isTypeOf($order) && self::of($order) !== null;
    }
}
