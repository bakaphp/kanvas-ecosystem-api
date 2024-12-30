<?php

declare(strict_types=1);

namespace Kanvas\ActionEngine\Engagements\DataTransferObject;

use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Attributes\MapOutputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

#[MapInputName(SnakeCaseMapper::class)]
#[MapOutputName(SnakeCaseMapper::class)]
class EngagementMessage extends Data
{
    public function __construct(
        public readonly array $data,
        public readonly string $text,
        public readonly string $verb,
        public readonly string $status,
        public readonly string $actionLink,
        public readonly string $source,
        public readonly string $linkPreview,
        public readonly string $engagementStatus,
        public readonly string $visitorId,
        #[MapOutputName('hashtagVisited')]
        public readonly ?string $hashtagVisited = null,
        public readonly ?string $userUuid = null,
        public readonly ?string $contactUuid = null,
        #[MapOutputName('checklistId')]
        #[MapInputName('checklistId')]
        public readonly ?int $checklistId = null,
    ) {
    }
}
