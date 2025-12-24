<?php

declare(strict_types=1);

namespace Kanvas\Souk\Loyalty\Enums;

enum OfferStatus: string
{
    case DRAFT = 'draft';
    case ACTIVE = 'active';
    case PAUSED = 'paused';
    case ARCHIVED = 'archived';
}
