<?php

declare(strict_types=1);

namespace Tests\Stubs\Social;

use Kanvas\Intelligence\Agents\Contracts\AgentApprovalHandler;
use Kanvas\Social\Messages\Models\Message;
use Override;

/**
 * A card action that records that it ran and with what, so tests can assert the dispatch and the
 * once-only guarantee without a real side effect to observe.
 */
class RecordingMessageApprovalHandler implements AgentApprovalHandler
{
    public const string KIND = 'recording_test';

    public static bool $ran = false;
    public static int $runs = 0;

    /** @var array<string, mixed> */
    public static array $context = [];

    public static function reset(): void
    {
        self::$ran = false;
        self::$runs = 0;
        self::$context = [];
    }

    /**
     * @param array<string, mixed> $context
     */
    #[Override]
    public function approve(Message $message, array $context): void
    {
        self::$ran = true;
        self::$runs++;
        self::$context = $context;
    }
}
