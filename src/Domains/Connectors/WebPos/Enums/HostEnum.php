<?php

declare(strict_types=1);

namespace Kanvas\Connectors\WebPos\Enums;

enum HostEnum: string
{
    case ISSUING = 'issuing';
    case RECEPTION = 'reception';
}
