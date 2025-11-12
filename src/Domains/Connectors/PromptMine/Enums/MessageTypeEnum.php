<?php

declare(strict_types=1);

namespace Kanvas\Connectors\PromptMine\Enums;

enum MessageTypeEnum: string
{
    case IMAGE_FORMAT = 'image-format';
    case VIDEO_FORMAT = 'video-format';
    case TEXT_FORMAT = 'text-format';
    case NUGGET = 'memo';
}
