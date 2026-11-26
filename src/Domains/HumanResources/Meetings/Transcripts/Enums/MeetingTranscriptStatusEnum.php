<?php

declare(strict_types=1);

namespace Kanvas\HumanResources\Meetings\Transcripts\Enums;

enum MeetingTranscriptStatusEnum: string
{
    case PENDING = 'pending';
    case PROCESSING = 'processing';
    case COMPLETED = 'completed';
    case FAILED = 'failed';
}
