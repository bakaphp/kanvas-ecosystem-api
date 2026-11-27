<?php

declare(strict_types=1);

namespace Tests\Workflow\Integration\Imports\Fakes;

use Kanvas\Workflow\Integrations\Imports\Enums\IntegrationImportStatusEnum;

final class FakeImportRegistry
{
    public static array $items = [];
    public static array $failOn = [];
    public static array $unlabelable = [];
    public static ?array $completed = null;
    public static array $imported = [];
    public static ?string $completedThrows = null;
    public static ?IntegrationImportStatusEnum $statusAtCompletion = null;
    public static int $prepared = 0;

    public static function reset(): void
    {
        self::$items = [];
        self::$failOn = [];
        self::$unlabelable = [];
        self::$completed = null;
        self::$imported = [];
        self::$completedThrows = null;
        self::$statusAtCompletion = null;
        self::$prepared = 0;
    }
}
