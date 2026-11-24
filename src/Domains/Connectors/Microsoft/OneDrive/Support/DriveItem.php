<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Microsoft\OneDrive\Support;

class DriveItem
{
    /**
     * Graph usually answers parentReference.path as "/drive/root:", but some responses (a folder
     * create on a personal account) qualify it as "/drives/{driveId}/root:".
     */
    private const string ROOT_PREFIX = '#^/drives?(/[^/]+)?/root:#';

    /**
     * @return array{id: string, name: string, type: string, size: int, path: ?string, web_url: ?string, modified_at: ?string, etag: ?string}
     */
    public static function fromGraph(array $item): array
    {
        $name = (string) ($item['name'] ?? '');
        $parentPath = $item['parentReference']['path'] ?? null;
        $path = null;

        if (is_string($parentPath)) {
            $parent = preg_replace(self::ROOT_PREFIX, '', $parentPath);
            $path = rtrim($parent, '/') . '/' . $name;
        }

        return [
            'id' => (string) ($item['id'] ?? ''),
            'name' => $name,
            'type' => isset($item['folder']) ? 'folder' : 'file',
            'size' => (int) ($item['size'] ?? 0),
            'path' => $path,
            'web_url' => $item['webUrl'] ?? null,
            'modified_at' => $item['lastModifiedDateTime'] ?? null,
            'etag' => $item['eTag'] ?? null,
        ];
    }
}
