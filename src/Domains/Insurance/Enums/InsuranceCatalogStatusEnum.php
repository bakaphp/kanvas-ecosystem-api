<?php

declare(strict_types=1);

namespace Kanvas\Insurance\Enums;

enum InsuranceCatalogStatusEnum: string
{
    case ACTIVE = 'active';
    case INACTIVE = 'inactive';
}
