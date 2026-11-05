<?php

declare(strict_types=1);

namespace Kanvas\Companies\Onboarding\Enums;

enum ResearchStatusEnum: string
{
    case NOT_STARTED = 'not_started';
    case QUEUED = 'queued';
    case COLLECTING = 'collecting';
    case EXTRACTING = 'extracting';
    case INDEXED = 'indexed';
    case FAILED = 'failed';
    case SKIPPED = 'skipped';

    public function isRunning(): bool
    {
        return $this === self::QUEUED || $this === self::COLLECTING || $this === self::EXTRACTING;
    }
}
