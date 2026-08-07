<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\DataTransferObject;

use Kanvas\Apps\Models\Apps;
use Spatie\LaravelData\Data;

class AgentKnowledgeDocument extends Data
{
    public function __construct(
        public readonly Apps $app,
        public readonly string $content,
        public readonly string $sourceType,
        public readonly string $sourceName,
    ) {
    }
}
