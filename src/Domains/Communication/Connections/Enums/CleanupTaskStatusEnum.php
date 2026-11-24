<?php

declare(strict_types=1);

namespace Kanvas\Communication\Connections\Enums;

enum CleanupTaskStatusEnum: string
{
    case PENDING = 'pending';
    case RUNNING = 'running';
    case DONE = 'done';

    // The vendor no longer holds what we set: a newer owner replaced it, so there is nothing of
    // ours left to remove.
    case SUPERSEDED = 'superseded';

    public function isFinished(): bool
    {
        return $this === self::DONE || $this === self::SUPERSEDED;
    }
}
