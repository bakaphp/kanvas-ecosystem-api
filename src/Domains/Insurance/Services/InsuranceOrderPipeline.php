<?php

declare(strict_types=1);

namespace Kanvas\Insurance\Services;

use Baka\Contracts\AppInterface;
use Kanvas\Insurance\Enums\InsuranceOrderStatusEnum;
use Kanvas\Insurance\Enums\InsuranceOrderTypeEnum;
use Kanvas\Souk\Orders\Models\OrderStatus;
use Kanvas\Souk\Orders\Models\OrderTypes;

class InsuranceOrderPipeline
{
    public const string SETUP_COMMAND = 'kanvas:insurance-setup-order-type';

    public static function isSeeded(AppInterface $app): bool
    {
        $orderType = OrderTypes::query()
            ->where('apps_id', $app->getId())
            ->where('name', InsuranceOrderTypeEnum::INSURANCE->value)
            ->first();

        if ($orderType === null) {
            return false;
        }

        $slugs = OrderStatus::query()
            ->where('order_types_id', $orderType->getId())
            ->where('is_deleted', false)
            ->pluck('is_default', 'slug');

        foreach (InsuranceOrderStatusEnum::cases() as $status) {
            if (! $slugs->has($status->value)) {
                return false;
            }
        }

        return $slugs->filter()->isNotEmpty();
    }
}
