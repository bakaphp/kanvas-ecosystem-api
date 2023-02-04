<?php
declare(strict_types=1);

namespace Tests\Feature\Companies\Actions;

use Illuminate\Support\Facades\Auth;
use Kanvas\Apps\Enums\Defaults;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Actions\UpdateCompaniesAction;
use Kanvas\Companies\DataTransferObject\CompaniesPutData;
use Kanvas\Companies\Models\Companies;
use Kanvas\Enums\StateEnums;
use Tests\TestCase;

final class UpdateCompaniesActionTest extends TestCase
{
    /**
     * Test Create Apps Action.
     *
     * @return void
     */
    public function testUpdateCompaniesAction() : void
    {
        $company = Companies::factory(1)->create()->first();
        $company->associateUser(Auth::user(), StateEnums::YES->getValue(), $company->branch()->first());
        $company->associateUserApp(Auth::user(), app(Apps::class), StateEnums::YES->getValue());

        $faker = \Faker\Factory::create();
        $data = [
            'currency_id' => $company->currency_id,
            'name' => $faker->company,
            'profile_image' => $company->profile_image,
            'website' => $company->website,
            'address' => $company->address,
            'zipcode' => (int) $company->zipcode,
            'email' => $company->email,
            'language' =>  Defaults::DEFAULT_LANGUAGE->getValue(),
            'timezone' => $company->timezone,
            'phone' => $company->phone,
            'country_code' => $company->country_code,
        ];

        $dtoData = CompaniesPutData::fromArray($data);

        $updateCompany = new UpdateCompaniesAction(Auth::user(), $dtoData);

        $this->assertInstanceOf(
            Companies::class,
            $updateCompany->execute($company->id)
        );
    }
}
