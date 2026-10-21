<?php

declare(strict_types=1);

namespace Tests\Traits;

use Kanvas\Apps\Models\Apps;

/**
 * App settings are shared state that outlives a database transaction, so a test
 * that writes one has to clean it up or it leaks into every test that follows.
 * Teardown puts back the value each key held before the test, so a developer's
 * own configured engine survives a local run, and runs even when a test fails partway.
 */
trait ManagesAppSettings
{
    /**
     * @var array<string, mixed>
     */
    private array $originalAppSettings = [];

    protected function tearDown(): void
    {
        $app = app(Apps::class);

        foreach ($this->originalAppSettings as $key => $value) {
            $value === null ? $app->del($key) : $app->set($key, $value);
        }

        $this->originalAppSettings = [];

        parent::tearDown();
    }

    /**
     * For keys the code under test writes itself. Call it before the first write: only the
     * first remembered value is kept, so a later call cannot record the test's own value.
     */
    protected function rememberAppSettings(string ...$keys): void
    {
        $app = app(Apps::class);

        foreach ($keys as $key) {
            if (! array_key_exists($key, $this->originalAppSettings)) {
                $this->originalAppSettings[$key] = $app->get($key);
            }
        }
    }

    /**
     * @param array<string, mixed> $settings
     */
    private function setAppSettings(array $settings): Apps
    {
        $this->rememberAppSettings(...array_keys($settings));
        $app = app(Apps::class);

        foreach ($settings as $key => $value) {
            $app->set($key, $value);
        }

        return $app;
    }
}
