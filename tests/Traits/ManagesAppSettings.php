<?php

declare(strict_types=1);

namespace Tests\Traits;

use Illuminate\Support\Facades\Redis;
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
            $value === null ? self::forgetAppSetting($app, $key) : $app->set($key, $value);
        }

        $this->originalAppSettings = [];

        parent::tearDown();
    }

    /**
     * del() only touches Redis when the row is still in the database; a test that transacts the
     * `ecosystem` connection rolled that row back already, and the Redis copy would stay live for the
     * rest of the run.
     */
    private static function forgetAppSetting(Apps $app, string $key): void
    {
        $app->del($key);
        Redis::hDel($app->getSettingsRedisPrimaryKey(), $key);
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
