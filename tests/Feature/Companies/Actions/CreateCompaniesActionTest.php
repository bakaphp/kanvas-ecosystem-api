<?php
declare(strict_types=1);

namespace Tests\Feature\Companies\Actions;

use Illuminate\Support\Facades\Auth;
use Kanvas\Companies\Actions\CreateCompaniesAction;
use Kanvas\Companies\DataTransferObject\CompaniesPostData;
use Kanvas\Companies\Models\Companies;
use Kanvas\Users\Models\Users;
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

        $dtoData = CompaniesPostData::fromArray($data);

        $company = new CreateCompaniesAction($dtoData);

        $this->assertInstanceOf(
            Companies::class,
            $company->execute()
        );
    }
}
