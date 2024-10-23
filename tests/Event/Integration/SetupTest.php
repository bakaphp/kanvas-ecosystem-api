<?php

declare(strict_types=1);

namespace Tests\Event\Integration;

use Kanvas\Apps\Models\Apps;
use Kanvas\Event\Events\Models\EventCategory;
use Kanvas\Event\Support\Setup;
use Tests\TestCase;

final class SetupTest extends TestCase
{
    public function testInitializeANewCompany(): void
    {
        $company = auth()->user()->getCurrentCompany();
        $setupCompany = new Setup(
            app(Apps::class),
            auth()->user(),
            $company
        );

        $this->assertTrue($setupCompany->run());
        $this->assertGreaterThan(0, EventCategory::where('companies_id', $company->getId())->count());
    }
}
