<?php

declare(strict_types=1);

namespace Kanvas\Enums;

use Baka\Contracts\EnumsInterface;
use Override;

enum AppB2BSettingsEnums implements EnumsInterface
{
    case KANVAS_APP_B2B_STATUS;
    case KANVAS_APP_B2B_MAIN_COMPANY_ID;
    case KANVAS_APP_B2B_MINIMUM_FREE_SHIPPING;
    case KANVAS_APP_B2B_MINIMUM_AMOUNT_ORDER;
    case KANVAS_APP_B2B_MAXIMUM_ITEMS_ORDER;

    #[Override]
    public function getValue(): mixed
    {
        return match ($this) {
            self::KANVAS_APP_B2B_STATUS => 'kanvas_app_b2b_status',
            self::KANVAS_APP_B2B_MAIN_COMPANY_ID => 'kanvas_app_b2b_main_company_id',
            self::KANVAS_APP_B2B_MINIMUM_FREE_SHIPPING => 'kanvas_app_b2b_minimum_free_shipping',
            self::KANVAS_APP_B2B_MINIMUM_AMOUNT_ORDER => 'kanvas_app_b2b_minimum_amount_order',
            self::KANVAS_APP_B2B_MAXIMUM_ITEMS_ORDER => 'kanvas_app_b2b_maximum_items_order',
        };
    }
}
