<?php

declare(strict_types=1);

namespace Tests\Workflow\Integration\Imports\Fakes;

final class FakeImportRegistry
{
    public static array $items = [];
    public static array $failOn = [];
    public static ?array $completed = null;
    public static array $imported = [];

    public static function reset(): void
    {
        self::$items = [];
        self::$failOn = [];
        self::$completed = null;
        self::$imported = [];
    }
}
