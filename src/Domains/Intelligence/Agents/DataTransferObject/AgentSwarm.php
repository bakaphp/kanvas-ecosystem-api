<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\DataTransferObject;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Baka\Users\Contracts\UserInterface;
use Kanvas\Intelligence\Agents\Enums\AgentSwarmStatusEnum;
use Spatie\LaravelData\Data;

class AgentSwarm extends Data
{
    /**
     * @param array<int>|null $agentIds
     * @param array<string, mixed>|null $config
     */
    public function __construct(
        public readonly AppInterface $app,
        public readonly CompanyInterface $company,
        public readonly UserInterface $user,
        public readonly string $name,
        public readonly ?string $description = null,
        public readonly AgentSwarmStatusEnum $status = AgentSwarmStatusEnum::DRAFT,
        public readonly ?array $config = null,
        public readonly ?array $agentIds = null,
    ) {
    }

    public function isActive(): bool
    {
        return $this->status === AgentSwarmStatusEnum::ACTIVE;
    }
}
