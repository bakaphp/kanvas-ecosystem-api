<?php

declare(strict_types=1);

namespace Tests\Stubs\Auth;

use Kanvas\Apps\Models\Apps;

/**
 * App settings live in Redis and MySQL, which every paratest process shares —
 * a test that tunes one on the real app races whatever another process is
 * reading at that moment. This double keeps them in memory so a unit test can
 * set a knob without touching state anyone else can see.
 */
final class InMemorySettingsApp extends Apps
{
    /**
     * @var array<string, mixed>
     */
    private array $memorySettings = [];

    /**
     * @param array<string, mixed> $settings
     */
    public static function withSettings(array $settings = [], int $id = 1): self
    {
        $app = new self();
        $app->id = $id;

        foreach ($settings as $key => $value) {
            $app->set($key, $value);
        }

        return $app;
    }

    public function set(string $key, mixed $value, bool|int $isPublic = 0): bool
    {
        $this->memorySettings[$key] = $value;

        return true;
    }

    public function get(string $key, mixed $defaultValue = null): mixed
    {
        return $this->memorySettings[$key] ?? $defaultValue;
    }

    public function del(string $key): bool
    {
        unset($this->memorySettings[$key]);

        return true;
    }
}
