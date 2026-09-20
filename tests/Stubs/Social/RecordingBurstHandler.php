<?php

declare(strict_types=1);

namespace Tests\Stubs\Social;

use Kanvas\Social\Messages\Support\BurstHandler;
use Override;

/**
 * Records that a burst closed and what it contained, so tests can assert the once-only guarantee
 * and the assembled prompt without a real agent turn to observe.
 */
class RecordingBurstHandler extends BurstHandler
{
    public static int $runs = 0;

    /** @var list<int> */
    public static array $messageIds = [];

    public static string $prompt = '';

    /** @var array<string, mixed> */
    public static array $params = [];

    public static function reset(): void
    {
        self::$runs = 0;
        self::$messageIds = [];
        self::$prompt = '';
        self::$params = [];
    }

    #[Override]
    public function execute(array $params = []): array
    {
        self::$runs++;
        self::$messageIds = $this->messageIds();
        self::$prompt = $this->prompt();
        self::$params = $params;

        return ['runs' => self::$runs];
    }
}
