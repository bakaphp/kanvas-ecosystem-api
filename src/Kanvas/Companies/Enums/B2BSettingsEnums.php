<?php

declare(strict_types=1);

namespace Kanvas\Companies\Enums;

use Baka\Contracts\EnumsInterface;
use Override;

enum B2BSettingsEnums implements EnumsInterface
{
    case B2B_COMPANY_MINIMUM_FREE_SHIPPING;
    case B2B_COMPANY_MINIMUM_AMOUNT_ORDER;
    case B2B_COMPANY_MAXIMUM_ITEMS_ORDER;
    case B2B_COMPANY_DISCOUNT;
    case B2B_COMPANY_STATUS;
    case B2B_APP_WISE_ORDER_NUMBERING;
    case B2B_COMPANY_ADMIN_EMAILS;

    #[Override]
    public function getValue(): mixed
    {
        return match ($this) {
            self::B2B_COMPANY_MINIMUM_FREE_SHIPPING => 'b2b_company_minimum_free_shipping',
            self::B2B_COMPANY_MINIMUM_AMOUNT_ORDER => 'b2b_company_minimum_amount_order',
            self::B2B_COMPANY_MAXIMUM_ITEMS_ORDER => 'b2b_company_maximum_items_order',
            self::B2B_COMPANY_DISCOUNT => 'b2b_company_discount',
            self::B2B_COMPANY_STATUS => 'b2b_company_status',
            self::B2B_APP_WISE_ORDER_NUMBERING => 'b2b_app_wise_order_numbering',
            self::B2B_COMPANY_ADMIN_EMAILS => "b2b_company_admin_emails"
        };
    }
}
