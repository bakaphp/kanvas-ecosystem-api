<?php

declare(strict_types=1);

namespace Kanvas\HumanResources\Meetings\Notes\Enums;

enum MeetingNoteKindEnum: string
{
    case MANUAL = 'manual';
    case ENHANCED = 'enhanced';
}
