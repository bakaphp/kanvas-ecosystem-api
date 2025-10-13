<?php

declare(strict_types=1);

namespace Kanvas\Event\Events\Enums;

enum EventPassScopeEnum: string
{
    case PARTICIPANT = 'participant';
    case EVENT = 'event';
}
