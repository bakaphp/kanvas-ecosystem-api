<?php

declare(strict_types=1);

namespace Tests\Ecosystem\Integration\Companies;

use Illuminate\Support\Facades\Auth;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Actions\UpdateCompaniesAction;
use Kanvas\Companies\DataTransferObject\Company;
use Kanvas\Companies\Models\Companies;
use Kanvas\Enums\AppEnums;
use Kanvas\Enums\StateEnums;
use Tests\TestCase;

final class UpdateCompaniesActionTest extends TestCase
{
    /**
     * Test Create Apps Action.
     */
    public function testUpdateCompaniesAction(): void
    {
        $company = Companies::factory(1)->create()->first();
        $company->associateUser(Auth::user(), StateEnums::YES->getValue(), $company->branch()->first());
        $company->associateUserApp(Auth::user(), app(Apps::class), StateEnums::YES->getValue());

        $faker = \Faker\Factory::create();
        $data = [
            'user' => $company->user,
            'currency_id' => $company->currency_id,
            'name' => $faker->company,
            'profile_image' => $company->profile_image,
            'website' => $company->website,
            'address' => $company->address,
            'zipcode' => (int) $company->zipcode,
            'email' => $company->email,
            'language' => AppEnums::DEFAULT_LANGUAGE->getValue(),
            'timezone' => $company->timezone,
            'phone' => $company->phone,
            'country_code' => $company->country_code,
        ];

        $dtoData = Company::from($data);

        $updateCompany = new UpdateCompaniesAction($company, Auth::user(), $dtoData);

        $this->assertInstanceOf(
            Companies::class,
            $updateCompany->execute()
        );
    }
}
