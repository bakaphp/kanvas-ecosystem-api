<?php

declare(strict_types=1);

namespace Tests\Guild\Customers;

use Kanvas\Apps\Models\AppKey;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\CompaniesBranches;
use Kanvas\Guild\Customers\Repositories\PeoplesRepository;
use Tests\TestCase;

final class PeoplePhoneLookupStaysInItsCompanyTest extends TestCase
{
    public function testAnAppKeyWithoutABranchDoesNotWidenTheLookupToEveryCompany(): void
    {
        $company = auth()->user()->getCurrentCompany();
        $branch = app()->bound(CompaniesBranches::class) ? app(CompaniesBranches::class) : null;

        app()->instance(AppKey::class, new AppKey());
        app()->offsetUnset(CompaniesBranches::class);

        try {
            $sql = PeoplesRepository::getByPhoneNumber(app(Apps::class), $company, ['8095550211'])->toRawSql();
        } finally {
            app()->forgetInstance(AppKey::class);

            if ($branch !== null) {
                app()->instance(CompaniesBranches::class, $branch);
            }
        }

        $this->assertStringContainsString('`companies_id` = ' . $company->getId(), $sql);
        $this->assertStringNotContainsString('`companies_id` > 0', $sql);
    }
}
