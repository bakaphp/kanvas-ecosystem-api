<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\BrushCrazy;

use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\BrushCrazy\Enums\ConfigurationEnum;
use Kanvas\Connectors\BrushCrazy\Handlers\BrushCrazyHandler;
use Kanvas\Regions\Models\Regions;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;
use Throwable;

/**
 * Serial: setup() writes the source credentials as app settings, which live in Redis and on
 * `ecosystem` and are rolled back by neither.
 *
 * The source database is unreachable on purpose: the flag is decided before setup() tests the
 * connection, so the failed connection is caught and only the flag is asserted.
 */
#[Group('serial')]
final class BrushCrazyHandlerTest extends TestCase
{
    private const array APP_SETTINGS = [
        ConfigurationEnum::BRUSHCRAZY_DB_HOST,
        ConfigurationEnum::BRUSHCRAZY_DB_PORT,
        ConfigurationEnum::BRUSHCRAZY_DB_DATABASE,
        ConfigurationEnum::BRUSHCRAZY_DB_USERNAME,
        ConfigurationEnum::BRUSHCRAZY_DB_PASSWORD,
        ConfigurationEnum::BRUSHCRAZY_DEFAULT_TIMEZONE,
        ConfigurationEnum::BRUSHCRAZY_DEFAULT_CURRENCY,
        ConfigurationEnum::BRUSHCRAZY_STUDIO_MODE,
    ];

    /** @var array<string, mixed> */
    private array $originalSettings = [];

    private Companies $company;

    private mixed $originalSyncEnabled;

    protected function setUp(): void
    {
        parent::setUp();

        $app = app(Apps::class);

        foreach (self::APP_SETTINGS as $key) {
            $this->originalSettings[$key->value] = $app->get($key->value);
        }

        $this->company = auth()->user()->getCurrentCompany();
        $this->originalSyncEnabled = $this->company->get(ConfigurationEnum::BRUSHCRAZY_SYNC_ENABLED->value);
    }

    protected function tearDown(): void
    {
        $app = app(Apps::class);

        foreach ($this->originalSettings as $key => $value) {
            $value !== null ? $app->set($key, $value) : $app->del($key);
        }

        $key = ConfigurationEnum::BRUSHCRAZY_SYNC_ENABLED->value;
        $this->originalSyncEnabled !== null ? $this->company->set($key, $this->originalSyncEnabled) : $this->company->del($key);

        parent::tearDown();
    }

    public function testFirstSetupEnablesTheStudio(): void
    {
        $this->company->del(ConfigurationEnum::BRUSHCRAZY_SYNC_ENABLED->value);

        $this->runSetup();

        $this->assertTrue($this->company->getBool(ConfigurationEnum::BRUSHCRAZY_SYNC_ENABLED->value));
    }

    /**
     * Regression: re-running setup to rotate the password turned the mirror of a paused studio
     * back on.
     */
    public function testReRunningSetupDoesNotResumeAPausedStudio(): void
    {
        $this->company->set(ConfigurationEnum::BRUSHCRAZY_SYNC_ENABLED->value, false);

        $this->runSetup();

        $this->assertFalse($this->company->getBool(ConfigurationEnum::BRUSHCRAZY_SYNC_ENABLED->value));
    }

    private function runSetup(): void
    {
        $handler = new BrushCrazyHandler(
            app(Apps::class),
            $this->company,
            Regions::query()->firstOrFail(),
            [
                'brushcrazy_db_host' => '127.0.0.1',
                'brushcrazy_db_port' => '1',
                'brushcrazy_db_database' => 'brushcrazy_unreachable',
                'brushcrazy_db_password' => 'rotated',
            ]
        );

        try {
            $handler->setup();
        } catch (Throwable) {
            // The unreachable source database; the flag was already decided.
        }
    }
}
