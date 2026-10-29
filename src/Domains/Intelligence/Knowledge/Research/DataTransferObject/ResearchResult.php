<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Knowledge\Research\DataTransferObject;

use Kanvas\Companies\Onboarding\Enums\ResearchStatusEnum;

/**
 * @param array<string, mixed> $profile
 */
final readonly class ResearchResult
{
    public function __construct(
        public ResearchStatusEnum $status,
        public ?string $collector = null,
        public int $pagesFound = 0,
        public int $pagesIndexed = 0,
        public int $knowledgeCount = 0,
        public array $profile = [],
        public ?string $error = null,
        public ?string $note = null,
    ) {
    }
}
