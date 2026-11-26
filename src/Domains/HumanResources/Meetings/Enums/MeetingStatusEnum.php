<?php

declare(strict_types=1);

namespace Kanvas\HumanResources\Meetings\Enums;

enum MeetingStatusEnum: string
{
    case AWAITING_UPLOAD = 'awaiting_upload';
    case UPLOADED = 'uploaded';
    case TRANSCRIBING = 'transcribing';
    case COMPLETED = 'completed';
    case FAILED = 'failed';
}
