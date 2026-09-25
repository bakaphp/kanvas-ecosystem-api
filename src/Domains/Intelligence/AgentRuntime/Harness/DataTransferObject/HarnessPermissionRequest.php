<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\AgentRuntime\Harness\DataTransferObject;

use Spatie\LaravelData\Data;

class HarnessPermissionRequest extends Data
{
    /**
     * @param list<string> $resources what the agent wants to act on — the shell command, the path
     */
    public function __construct(
        public readonly string $id,
        public readonly string $action,
        public readonly array $resources = [],
        public readonly ?string $callId = null,
    ) {
    }

    public function describe(): string
    {
        return $this->resources === []
            ? $this->action
            : $this->action . ': ' . implode(', ', $this->resources);
    }
}
