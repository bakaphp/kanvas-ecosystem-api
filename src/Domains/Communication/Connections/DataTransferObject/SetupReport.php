<?php

declare(strict_types=1);

namespace Kanvas\Communication\Connections\DataTransferObject;

/**
 * What one setup pass did to one integration — the line `communication:setup` prints.
 */
final readonly class SetupReport
{
    public const string READY = 'ready';
    public const string BLOCKED = 'blocked';
    public const string FAILED = 'failed';
    public const string SKIPPED = 'skipped';

    /**
     * @param array<string, string> $connections account key → its setup status, or the reason it is not ready
     */
    public function __construct(
        public string $outcome,
        public ?string $reason = null,
        public array $connections = []
    ) {
    }

    public static function skipped(string $reason): self
    {
        return new self(self::SKIPPED, $reason);
    }

    /**
     * @param array<string, string> $connections
     */
    public static function of(array $connections, array $blocked, array $failed): self
    {
        return match (true) {
            $failed !== [] => new self(self::FAILED, implode('; ', $failed), $connections),
            $blocked !== [] => new self(self::BLOCKED, implode('; ', $blocked), $connections),
            default => new self(self::READY, null, $connections),
        };
    }
}
