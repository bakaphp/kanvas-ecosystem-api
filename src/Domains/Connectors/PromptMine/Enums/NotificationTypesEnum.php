<?php

declare(strict_types=1);

namespace Kanvas\Connectors\PromptMine\Enums;

enum NotificationTypesEnum: string
{
    case FOLLOW_RECOMMENDATION = 'follow-recommendation';
    case IMAGE_PROCESSING = 'image-processing';
    case VIDEO_PROCESSING = 'video-processing';
    case MESSAGE_OF_THE_WEEK = 'message-of-the-week';
    case MONTHLY_MESSAGE_CREATION = 'monthly-message-creation';
    case NEW_MESSAGE = 'new-message';
}