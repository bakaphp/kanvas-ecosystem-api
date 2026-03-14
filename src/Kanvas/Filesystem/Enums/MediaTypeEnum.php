<?php

declare(strict_types=1);

namespace Kanvas\Filesystem\Enums;

enum MediaTypeEnum: string
{
    case IMAGE = 'image';
    case VIDEO = 'video';
    case AUDIO = 'audio';
    case DOCUMENT = 'document';
    case UNKNOWN = 'unknown';

    private const IMAGE_EXTENSIONS = [
        'jpg',
        'jpeg',
        'png',
        'gif',
        'webp',
        'heic',
        'heif',
        'svg',
        'tif',
        'tiff',
        'bmp',
    ];

    private const VIDEO_EXTENSIONS = [
        'mp4',
        'mov',
        'avi',
        'wmv',
        'mkv',
        'webm',
        'flv',
        'm4v',
        '3gp',
        'ogg',
    ];

    private const AUDIO_EXTENSIONS = [
        'mp3',
        'wav',
        'ogg',
        'aac',
        'm4a',
        'wma',
        'flac',
    ];

    private const DOCUMENT_EXTENSIONS = [
        'pdf',
        'doc',
        'docx',
        'xls',
        'xlsx',
        'ppt',
        'pptx',
        'txt',
        'csv',
        'odt',
        'ods',
        'odp',
        'json',
    ];

    public static function fromExtension(string $extension): self
    {
        $ext = strtolower(trim($extension, '.'));

        return match (true) {
            in_array($ext, self::IMAGE_EXTENSIONS) => self::IMAGE,
            in_array($ext, self::VIDEO_EXTENSIONS) => self::VIDEO,
            in_array($ext, self::AUDIO_EXTENSIONS) => self::AUDIO,
            in_array($ext, self::DOCUMENT_EXTENSIONS) => self::DOCUMENT,
            default => self::UNKNOWN,
        };
    }

    public function isImage(): bool
    {
        return $this === self::IMAGE;
    }

    public function isVideo(): bool
    {
        return $this === self::VIDEO;
    }

    public function isAudio(): bool
    {
        return $this === self::AUDIO;
    }

    public function isDocument(): bool
    {
        return $this === self::DOCUMENT;
    }
}
