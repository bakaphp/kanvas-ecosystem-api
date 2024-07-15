<?php

declare(strict_types=1);

namespace Tests\Ecosystem\Integration\Companies;

use Illuminate\Support\Facades\Auth;
use Kanvas\Companies\Actions\CreateCompaniesAction;
use Kanvas\Companies\DataTransferObject\Company;
use Kanvas\Companies\Models\Companies;
use Kanvas\Users\Models\Users;
use Tests\TestCase;

final class CreateCompaniesActionTest extends TestCase
{
    /**
     * Test Create Apps Action.
     */
    public function testCreateCompaniesAction(): void
    {
        $faker = \Faker\Factory::create();
        $user = Users::factory(1)->create()->first();
        $data = [
            'name' => $faker->company,
            'users_id' => Auth::user()->id,
        ];

        $dtoData = Company::viaRequest($data, $user);

        $company = new CreateCompaniesAction($dtoData);

        $this->assertInstanceOf(
            Companies::class,
            $company->execute()
        );
    }
}
