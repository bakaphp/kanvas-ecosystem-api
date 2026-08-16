<?php

declare(strict_types=1);

namespace Kanvas\Connectors\WordPress\Services;

use Baka\Http\SafeUrlFetcher;
use finfo;
use Kanvas\Connectors\WordPress\RestClient;
use Throwable;

/**
 * Copies a file into the WordPress media library. URLs reach here straight from an agent-authored
 * message body, so every fetch goes through the SSRF-guarded fetcher.
 */
class WordPressMediaService
{
    private const int MAX_FILENAME_LENGTH = 120;

    private const array EXTENSION_BY_MIME = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/gif' => 'gif',
        'image/webp' => 'webp',
        'image/avif' => 'avif',
        'image/heic' => 'heic',
        'video/mp4' => 'mp4',
        'audio/mpeg' => 'mp3',
        'application/pdf' => 'pdf',
    ];

    /** @var array<string, int> */
    private array $uploaded = [];

    /** @var array<string, string> */
    private array $failures = [];

    public function __construct(private readonly RestClient $client)
    {
    }

    public function upload(string $url): ?int
    {
        if (isset($this->uploaded[$url])) {
            return $this->uploaded[$url];
        }

        try {
            $contents = SafeUrlFetcher::fetch($url);
        } catch (Throwable $e) {
            return $this->fail($url, $e->getMessage());
        }

        if ($contents === '') {
            return $this->fail($url, 'empty response body');
        }

        $mimeType = (string) (new finfo(FILEINFO_MIME_TYPE)->buffer($contents) ?: 'application/octet-stream');

        try {
            $media = $this->client->uploadMedia(
                $this->filename($url, $mimeType),
                $contents,
                $mimeType
            );
        } catch (Throwable $e) {
            return $this->fail($url, $e->getMessage());
        }

        return $this->uploaded[$url] = (int) $media['id'];
    }

    /**
     * @param list<string> $urls
     *
     * @return list<int>
     */
    public function uploadMany(array $urls): array
    {
        $ids = [];

        foreach ($urls as $url) {
            $id = $this->upload($url);

            if ($id !== null) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    /**
     * A dead URL must not sink the post, but it has to surface in the integration history —
     * otherwise the post silently ships with no images.
     *
     * @return array<string, string>
     */
    public function failures(): array
    {
        return $this->failures;
    }

    private function fail(string $url, string $reason): null
    {
        $this->failures[$url] = mb_substr($reason, 0, 300);

        return null;
    }

    /**
     * WP rejects an upload whose extension is not in its allow-list, and signed S3 URLs often have
     * no extension in the path at all — fall back to one derived from the sniffed content type.
     */
    private function filename(string $url, string $mimeType): string
    {
        $name = (string) preg_replace(
            '/[^A-Za-z0-9._-]/',
            '-',
            basename((string) parse_url($url, PHP_URL_PATH))
        );

        if ($name === '' || pathinfo($name, PATHINFO_EXTENSION) === '') {
            $extension = self::EXTENSION_BY_MIME[$mimeType] ?? 'bin';
            $name = ($name !== '' ? $name : 'kanvas-' . substr(sha1($url), 0, 12)) . '.' . $extension;
        }

        return mb_substr($name, -self::MAX_FILENAME_LENGTH);
    }
}
