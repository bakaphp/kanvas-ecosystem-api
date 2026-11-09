<?php

declare(strict_types=1);

namespace Tests\Connectors\Traits;

use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\WebPos\Enums\ConfigurationEnum;

/**
 * WebPOS settings go on a throwaway company, never the shared test company: company settings are written to
 * Redis, which no transaction rolls back, and `scribe-ecf-provider` on the shared company would switch every
 * parallel Scribe test onto e-CF numbering.
 *
 * Every credential and RNC here is synthetic.
 */
trait HasWebPosConfiguration
{
    use HasThrowawayCompany;

    protected const string WEBPOS_TEST_HOST = 'https://fedotest-api.webposonline.com';
    protected const string WEBPOS_TEST_RECEPTION_HOST = 'https://fedotest-api02.webposonline.com';
    protected const string WEBPOS_LICENSE = 'TESTLIC1';
    protected const string WEBPOS_API_KEY = '00000000-0000-4000-8000-00000000test';

    protected function webPosCompany(): Companies
    {
        $company = $this->freshCompany();
        $company->setEncrypted(ConfigurationEnum::API_KEY->value, self::WEBPOS_API_KEY);
        $company->set(ConfigurationEnum::COMPANY_LICENSE->value, self::WEBPOS_LICENSE);
        $company->set(ConfigurationEnum::BRANCH->value, '0001');
        $company->set(ConfigurationEnum::POS->value, '001');

        return $company;
    }
}
