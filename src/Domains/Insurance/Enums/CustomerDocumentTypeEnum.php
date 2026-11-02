<?php

declare(strict_types=1);

namespace Kanvas\Insurance\Enums;

enum CustomerDocumentTypeEnum: string
{
    case NATIONAL_ID = 'national_id';
    case PASSPORT = 'passport';
}
