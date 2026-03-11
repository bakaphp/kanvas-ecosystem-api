<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Enums;

enum HandOffTypeEnum: string
{
    case HUMAN = 'human';
    case SERVICE = 'service';
    case COMPLIANCE_INTERNAL = 'compliance_internal';
}
