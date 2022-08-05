<?php
declare(strict_types=1);

namespace Tests\Feature\Companies\Companies\Actions;

use Kanvas\Companies\Companies\Actions\CreateCompaniesAction;
use Kanvas\Companies\Companies\DataTransferObject\CompaniesPostData;
use Kanvas\Companies\Companies\Models\Companies;
use Kanvas\Users\Users\Models\Users;
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
            'name' => $faker->company
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
