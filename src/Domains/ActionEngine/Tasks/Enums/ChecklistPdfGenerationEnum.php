<?php

declare(strict_types=1);

namespace Kanvas\ActionEngine\Tasks\Enums;

/**
 * No COMPLETED case on purpose: a finished PDF's entry is removed, so success is its absence.
 */
enum ChecklistPdfGenerationEnum: string
{
    case GENERATING = 'generating';
    case FAILED = 'failed';
}
