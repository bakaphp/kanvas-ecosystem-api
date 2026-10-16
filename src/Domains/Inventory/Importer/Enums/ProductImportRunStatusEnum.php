<?php

declare(strict_types=1);

namespace Kanvas\Inventory\Importer\Enums;

enum ProductImportRunStatusEnum: string
{
    case OPEN = 'open';
    case COMPLETED = 'completed';
    case SKIPPED = 'skipped';
    case ABANDONED = 'abandoned';
}
