<?php

declare(strict_types=1);

namespace Kanvas\Companies\Enums;

use Baka\Contracts\EnumsInterface;
use Kanvas\Enums\AppEnums;

enum Defaults implements EnumsInterface
{
    case DEFAULT_COMPANY;
    case DEFAULT_COMPANY_APP;
    case PAYMENT_GATEWAY_CUSTOMER_KEY;
    case DEFAULT_COMPANY_BRANCH_APP;
    case GLOBAL_COMPANIES_ID;
    case SEARCHABLE_INDEX;
    case ALLOW_DUPLICATE_CONTACTS;

    public function getValue(): mixed
    {
        $appDefaults = AppEnums::GLOBAL_COMPANY_ID;

        return match ($this) {
            self::DEFAULT_COMPANY => 'DefaulCompany',
            self::DEFAULT_COMPANY_APP => 'DefaulCompanyApp_',
            self::PAYMENT_GATEWAY_CUSTOMER_KEY => 'payment_gateway_customer_id',
            self::DEFAULT_COMPANY_BRANCH_APP => 'DefaultCompanyBranchApp_',
            self::GLOBAL_COMPANIES_ID => $appDefaults->getValue(),
            self::SEARCHABLE_INDEX => 'companies',
            self::ALLOW_DUPLICATE_CONTACTS => 'feat_allow_duplicate_contacts',
        };
    }
}
