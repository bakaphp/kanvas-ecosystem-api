<?php

declare(strict_types=1);

namespace Tests\Traits;

use Kanvas\Apps\Models\Apps;

/**
 * App settings are shared state that outlives a database transaction, so a test
 * that writes one has to clean it up or it leaks into every test that follows.
 * Registering writes here means cleanup happens even when a test fails partway.
 */
trait ManagesAppSettings
{
    /**
     * @var list<string>
     */
    private array $touchedAppSettings = [];

    protected function tearDown(): void
    {
        $app = app(Apps::class);

        foreach ($this->touchedAppSettings as $key) {
            $app->del($key);
        }

        $this->touchedAppSettings = [];

        parent::tearDown();
    }

    /**
     * @param array<string, mixed> $settings
     */
    private function setAppSettings(array $settings): Apps
    {
        $app = app(Apps::class);

        foreach ($settings as $key => $value) {
            $app->set($key, $value);
            $this->touchedAppSettings[] = $key;
        }

        return $app;
    }
}
