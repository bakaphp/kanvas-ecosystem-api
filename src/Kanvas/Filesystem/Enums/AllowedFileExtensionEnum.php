<?php

declare(strict_types=1);

namespace Kanvas\Filesystem\Enums;

enum AllowedFileExtensionEnum
{
    case ONLY_IMAGES;
    case WORK_FILES;
    case MEDIA_FILES;

    public function getAllowedExtensions(): array
    {
        return match ($this) {
            self::ONLY_IMAGES => [
                'jpg',
                'jpeg',
                'tif',
                'png',
                'gif',
                'svg',
                'webp',
                'json',
                'pdf',
                'txt',
                'text',
                'heic',
                'heif',
                'avif',
            ],

            self::WORK_FILES => [
                'jpg',
                'jpeg',
                'png',
                'gif',
                'svg',
                'webp',
                'doc',
                'docx',
                'xls',
                'xlsx',
                'ppt',
                'pptx',
                'txt',
                'pdf',
                'csv',
                'odt',
                'ods',
                'odp',
                'json',
                'heic',
                'heif',
                'avif',
                'mp4',
                'mov',
                'avi',
                'wmv',
                'mkv',
                'webm',
                'flv',
                'm4v',
                '3gp',
                'mpeg',
                'mpg',
            ],

            self::MEDIA_FILES => [
                'jpg',
                'jpeg',
                'tif',
                'png',
                'gif',
                'svg',
                'webp',
                'heic',
                'heif',
                'avif',
                'json',
                'pdf',
                'txt',
                'text',
                'mp4',
                'mov',
                'avi',
                'wmv',
                'mkv',
                'webm',
                'flv',
                'm4v',
                '3gp',
                'mpeg',
                'mpg',
            ],
        };
    }
}
