<?php

declare(strict_types=1);

namespace Kanvas\HumanResources\Meetings\Recordings\DataTransferObject;

use Kanvas\HumanResources\Meetings\Models\Meeting;
use Spatie\LaravelData\Data;

class MeetingRecording extends Data
{
    public function __construct(
        public readonly Meeting $meeting,
        public readonly string $storageDisk,
        public readonly string $storageKey,
        public readonly string $mimeType,
        public readonly int $sizeBytes,
        public readonly string $sha256,
        public readonly ?int $durationMs = null,
        public readonly ?array $channelMap = null,
    ) {
    }

    public static function fromMultiple(Meeting $meeting, array $data): self
    {
        return new self(
            meeting: $meeting,
            storageDisk: (string) ($data['storage_disk'] ?? config('filesystems.default')),
            storageKey: (string) $data['storage_key'],
            mimeType: (string) $data['mime_type'],
            sizeBytes: (int) $data['size_bytes'],
            sha256: strtolower((string) $data['sha256']),
            durationMs: isset($data['duration_ms']) ? (int) $data['duration_ms'] : null,
            channelMap: $data['channel_map'] ?? null,
        );
    }
}
