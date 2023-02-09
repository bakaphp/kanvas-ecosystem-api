<?php

declare(strict_types=1);

namespace Kanvas\Companies\Enums;

use Baka\Contracts\EnumsInterface;
use Kanvas\Apps\Enums\Defaults as AppsDefaults;

enum Defaults implements EnumsInterface
{
    case DEFAULT_COMPANY;
    case DEFAULT_COMPANY_APP;
    case PAYMENT_GATEWAY_CUSTOMER_KEY;
    case DEFAULT_COMPANY_BRANCH_APP;
    case GLOBAL_COMPANIES_ID;

    public function getValue() : mixed
    {
        $appDefaults = AppsDefaults::GLOBAL_COMPANY_ID;
        return match ($this) {
            self::DEFAULT_COMPANY => 'DefaulCompany',
            self::DEFAULT_COMPANY_APP => 'DefaulCompanyApp_',
            self::PAYMENT_GATEWAY_CUSTOMER_KEY => 'payment_gateway_customer_id',
            self::DEFAULT_COMPANY_BRANCH_APP => 'DefaultCompanyBranchApp_',
            self::GLOBAL_COMPANIES_ID => $appDefaults->getValue(),
        };
    }
}
