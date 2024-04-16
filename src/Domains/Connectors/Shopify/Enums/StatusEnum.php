<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Shopify\Enums;

enum StatusEnum: string
{
    case ACTIVE = 'active';
    case DRAFT = 'draft';
    case ARCHIVED = 'archived';
}
