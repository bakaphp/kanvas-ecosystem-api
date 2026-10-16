<?php

declare(strict_types=1);

namespace Kanvas\Insurance\Services;

use Kanvas\Insurance\Enums\InsuranceCustomFieldEnum;
use Kanvas\Insurance\Enums\InsuranceStatusEnum;
use Kanvas\Souk\Orders\DataTransferObject\OrderReceipt;
use Kanvas\Souk\Orders\Models\Order;

class InsuranceCarnet
{
    public const string TEMPLATE = 'insurance-carnet';

    public const string EMAIL_TEMPLATE = 'insurance-policy-issued';

    public static function receipt(): OrderReceipt
    {
        return new OrderReceipt(
            template: self::TEMPLATE,
            filenamePattern: '{order_number}_carnet',
            activityLog: 'INSURANCE_CARNET_GENERATED',
            urlCustomField: InsuranceCustomFieldEnum::CARNET_URL->value,
        );
    }

    public static function hasIssuedPolicy(Order $order): bool
    {
        return $order->get(InsuranceCustomFieldEnum::STATUS->value) === InsuranceStatusEnum::POLICY_ACTIVE->value
            && ! empty($order->get(InsuranceCustomFieldEnum::POLICY_NUMBER->value));
    }

    public static function url(Order $order): ?string
    {
        $url = $order->get(InsuranceCustomFieldEnum::CARNET_URL->value);

        return empty($url) ? null : (string) $url;
    }
}
