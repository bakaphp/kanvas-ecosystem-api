<?php

declare(strict_types=1);

namespace Kanvas\Insurance\Enums;

use Illuminate\Database\Eloquent\Model;

enum InsuranceOrderOriginEnum: string
{
    case REGISTERED = 'registered';

    public const string PURCHASE_FLOW_SKIPPED = 'Registered policy: the purchase flow does not apply';

    public static function isRegistered(Model $order): bool
    {
        return (string) $order->get(InsuranceCustomFieldEnum::ORIGIN->value) === self::REGISTERED->value;
    }
}
