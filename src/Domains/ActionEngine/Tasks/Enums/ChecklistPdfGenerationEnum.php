<?php

declare(strict_types=1);

namespace Kanvas\ActionEngine\Tasks\Enums;

/**
 * Only the states that get stored. A finished PDF has no entry at all — success is the absence of
 * the record, so there is deliberately no COMPLETED case to persist.
 */
enum ChecklistPdfGenerationEnum: string
{
    case GENERATING = 'generating';
    case FAILED = 'failed';
}
