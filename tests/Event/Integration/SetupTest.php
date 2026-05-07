<?php

declare(strict_types=1);

namespace Tests\Event\Integration;

use Kanvas\Apps\Models\Apps;
use Kanvas\Event\Events\Models\EventCategory;
use Kanvas\Event\Events\Models\EventClass;
use Kanvas\Event\Events\Models\EventType;
use Kanvas\Event\Support\Enums\EventSetupTypeEnum;
use Kanvas\Event\Support\Setup;
use Kanvas\Event\Themes\Models\Theme;
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

    public function testStandardSetupCreatesMinimalData(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $setup = new Setup($app, $user, $company, EventSetupTypeEnum::STANDARD);
        $this->assertTrue($setup->run());

        $this->assertEquals(1, Theme::fromApp($app)->fromCompany($company)->count());
        $this->assertEquals(1, EventType::fromApp($app)->fromCompany($company)->count());
        $this->assertEquals(1, EventClass::fromApp($app)->fromCompany($company)->count());
        $this->assertEquals(1, EventCategory::fromApp($app)->fromCompany($company)->count());

        $this->assertEquals('Business', Theme::fromApp($app)->fromCompany($company)->first()->name);
        $this->assertEquals('Appointment', EventType::fromApp($app)->fromCompany($company)->first()->name);
        $this->assertEquals('Free', EventClass::fromApp($app)->fromCompany($company)->first()->name);
    }

    public function testFullSetupCreatesAllData(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $setup = new Setup($app, $user, $company, EventSetupTypeEnum::FULL);
        $this->assertTrue($setup->run());

        $this->assertGreaterThan(1, Theme::fromApp($app)->fromCompany($company)->count());
        $this->assertGreaterThan(1, EventType::fromApp($app)->fromCompany($company)->count());
        $this->assertGreaterThan(1, EventClass::fromApp($app)->fromCompany($company)->count());
        $this->assertGreaterThan(1, EventCategory::fromApp($app)->fromCompany($company)->count());
    }
}
