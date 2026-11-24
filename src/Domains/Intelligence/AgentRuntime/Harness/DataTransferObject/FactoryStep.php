<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\AgentRuntime\Harness\DataTransferObject;

use Kanvas\Intelligence\Agents\Models\Agent;

final readonly class FactoryStep
{
    public function __construct(
        public string $title,
        public string $instructions,
        public Agent $agent,
    ) {
    }
}
