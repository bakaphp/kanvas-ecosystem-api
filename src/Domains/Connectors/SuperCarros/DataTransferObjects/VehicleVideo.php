<?php

declare(strict_types=1);

namespace Kanvas\Connectors\SuperCarros\DataTransferObjects;

use Spatie\LaravelData\Data;

class VehicleVideo extends Data
{
    public function __construct(
        public bool $hasVideo,
        public string $source,
        public string $videoId,
        public bool $muted,
        public string $embedUrl
    ) {
    }

    public static function fromArray(array $data): self
    {
        return new self(
            hasVideo: $data['HasVideo'] ?? false,
            source: $data['VideoSource'] ?? '',
            videoId: $data['VideoId'] ?? '',
            muted: $data['VideoMuted'] ?? false,
            embedUrl: $data['VideoEmbedUrl'] ?? ''
        );
    }
}
