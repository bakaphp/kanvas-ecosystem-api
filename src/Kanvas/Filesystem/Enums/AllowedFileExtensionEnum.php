<?php

declare(strict_types=1);

namespace Kanvas\Filesystem\Enums;

enum AllowedFileExtensionEnum
{
    case ONLY_IMAGES;
    case WORK_FILES;

    public function getAllowedExtensions(): array
    {
        return match ($this) {
            self::ONLY_IMAGES => ['jpg', 'jpeg', 'png', 'gif', 'svg', 'webp', 'json', 'pdf', 'txt', 'text'],
            self::WORK_FILES => ['jpg', 'jpeg', 'png', 'gif', 'svg', 'webp', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'txt', 'pdf'],
        };
    }
}
