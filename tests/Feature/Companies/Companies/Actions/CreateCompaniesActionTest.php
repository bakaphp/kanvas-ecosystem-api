<?php
declare(strict_types=1);

namespace Tests\Feature\Companies\Companies\Actions;

use Illuminate\Support\Facades\Auth;
use Kanvas\CompanyGroup\Companies\Actions\CreateCompaniesAction;
use Kanvas\CompanyGroup\Companies\DataTransferObject\CompaniesPostData;
use Kanvas\CompanyGroup\Companies\Models\Companies;
use Kanvas\UsersGroup\Users\Models\Users;
use Tests\TestCase;

final class CreateCompaniesActionTest extends TestCase
{
    /**
     * Test Create Apps Action.
     *
     * @return void
     */
    public function testCreateCompaniesAction() : void
    {
        $faker = \Faker\Factory::create();
        $user = Users::factory(1)->create()->first();
        $data = [
            'name' => $faker->company,
            'users_id' => Auth::user()->id
        ];
        //Create new AppsPostData
        $dtoData = CompaniesPostData::fromArray($data);

        $company = new CreateCompaniesAction($dtoData);

        $this->assertInstanceOf(
            Companies::class,
            $company->execute()
        );
    }
}
