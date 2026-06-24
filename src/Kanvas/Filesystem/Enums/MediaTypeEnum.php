<?php

declare(strict_types=1);

namespace Kanvas\Filesystem\Enums;

use Kanvas\Filesystem\Models\Filesystem;

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

    public static function fromFilesystem(Filesystem $file): self
    {
        $fileType = strtolower(trim($file->file_type));

        if (str_contains($fileType, '/')) {
            $byMimePrefix = match (strstr($fileType, '/', true)) {
                'image' => self::IMAGE,
                'video' => self::VIDEO,
                'audio' => self::AUDIO,
                default => null,
            };

            if ($byMimePrefix !== null) {
                return $byMimePrefix;
            }
        }

        $path = parse_url($file->url, PHP_URL_PATH);

        $extensionCandidates = [
            $fileType,
            pathinfo($file->name, PATHINFO_EXTENSION),
            is_string($path) ? pathinfo($path, PATHINFO_EXTENSION) : '',
        ];

        foreach ($extensionCandidates as $candidate) {
            if ($candidate === '') {
                continue;
            }

            $type = self::fromExtension($candidate);

            if ($type !== self::UNKNOWN) {
                return $type;
            }
        }

        return self::UNKNOWN;
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
