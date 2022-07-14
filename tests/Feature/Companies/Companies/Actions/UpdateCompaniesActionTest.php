<?php
declare(strict_types=1);

namespace Tests\Feature\Apps\Apps\Actions;

use Kanvas\Companies\Companies\Actions\UpdateCompaniesAction;
use Kanvas\Companies\Companies\DataTransferObject\CompaniesPutData;
use Kanvas\Companies\Companies\Models\Companies;
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
        $faker = \Faker\Factory::create();
        $data = [
            'currency_id' => $company->currency_id,
            'name' => $faker->company,
            'profile_image' => $company->profile_image,
            'website' => $company->website,
            'address'=> $company->address,
            'zipcode' =>  $company->zipcode,
            'email' => $company->email,
            'language' => $company->language,
            'timezone' => $company->timezone,
            'phone' => $company->phone,
            'country_code' => $company->country_code,
        ];

        $dtoData = CompaniesPutData::fromArray($data);

        $updateCompany = new UpdateCompaniesAction($dtoData);

        $this->assertInstanceOf(
            Companies::class,
            $updateCompany->execute($company->id)
        );
    }
}
