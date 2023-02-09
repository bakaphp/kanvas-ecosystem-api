<?php
declare(strict_types=1);

namespace Tests\Inventory\Integration;

use Kanvas\Apps\Models\Apps;
use Kanvas\Inventory\Categories\Models\Categories;
use Kanvas\Inventory\Support\Setup;
use Tests\TestCase;

final class SetupTest extends TestCase
{
    public function testInitializeANewCompany() : void
    {
        $company = auth()->user()->getCurrentCompany();
        $setupCompany = new Setup(
            app(Apps::class),
            auth()->user(),
            $company
        );

        $this->assertTrue($setupCompany->run());
        $this->assertGreaterThan(0, Categories::where('companies_id', $company->getId())->count());
    }
}
