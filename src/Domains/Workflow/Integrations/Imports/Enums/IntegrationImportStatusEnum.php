<?php

declare(strict_types=1);

namespace Kanvas\Workflow\Integrations\Imports\Enums;

enum IntegrationImportStatusEnum: string
{
    case QUEUED = 'queued';
    case RUNNING = 'running';
    case COMPLETED = 'completed';
    case FAILED = 'failed';

    public function isInProgress(): bool
    {
        return $this === self::QUEUED || $this === self::RUNNING;
    }
}
