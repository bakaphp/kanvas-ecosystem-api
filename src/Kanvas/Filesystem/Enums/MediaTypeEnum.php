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

    /**
     * The filename extension a mimetype should be stored under.
     *
     * Lives here rather than in each connector because it is the same knowledge in both directions
     * as `fromExtension()`, and because getting it wrong is expensive elsewhere: WordPress judges
     * an upload by filename, so a JPEG saved as `.bin` is refused outright.
     *
     * Only the subtypes whose family default would be wrong are listed — a png named `.jpg` is a
     * lie something downstream eventually trips over.
     */
    public static function extensionForMime(string $mimeType): string
    {
        $mimeType = strtolower(trim(explode(';', $mimeType)[0]));

        $exact = [
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
            'image/avif' => 'avif',
            'image/heic' => 'heic',
            'video/quicktime' => 'mov',
            'video/webm' => 'webm',
            'video/3gpp' => '3gp',
            'video/x-matroska' => 'mkv',
            'audio/mpeg' => 'mp3',
            'audio/mp4' => 'm4a',
            'application/pdf' => 'pdf',
        ];

        return $exact[$mimeType] ?? match (explode('/', $mimeType)[0]) {
            'image' => 'jpg',
            'video' => 'mp4',
            'audio' => 'ogg',
            default => 'bin',
        };
    }

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
