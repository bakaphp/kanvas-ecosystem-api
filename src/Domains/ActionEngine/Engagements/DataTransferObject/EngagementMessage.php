<?php

declare(strict_types=1);

namespace Kanvas\ActionEngine\Engagements\DataTransferObject;

use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

#[MapInputName(SnakeCaseMapper::class)]
class EngagementMessage extends Data
{
    public function __construct(
        public readonly array $data,
        public readonly string $text,
        public readonly string $verb,
        public readonly string $actionLink,
        public readonly string $source,
        public readonly string $linkPreview,
        public readonly string $engagementStatus,
        public readonly string $visitorId,
        public readonly ?string $hashtagVisited,
        public readonly ?string $userUuid,
        public readonly ?string $status,
        public readonly ?string $contactUuid,
    ) {
    }
}
