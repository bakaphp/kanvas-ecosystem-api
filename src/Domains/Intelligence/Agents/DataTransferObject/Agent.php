<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\DataTransferObject;

use Kanvas\ActionEngine\Tasks\Models\TaskList;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Intelligence\Agents\Models\Agent as AgentModel;
use Kanvas\Intelligence\Agents\Models\AgentModel as AgentAiModel;
use Kanvas\Intelligence\Agents\Models\AgentType;
use Kanvas\Users\Models\Users;
use Spatie\LaravelData\Data;

class Agent extends Data
{
    public function __construct(
        public Apps $app,
        public Companies $company,
        public Users $user,
        public AgentType $agentType,
        public string $name,
        public string|array $role,
        public bool $is_active,
        public ?AgentAiModel $agentModel = null,
        public ?string $description = null,
        public string|array|null $config = null,
        public ?TaskList $task = null,
        public array $communicationChannel = [],
        public ?string $soul = null,
        public ?string $instructions = null,
        public ?string $outputFormat = null,
        public array|string|null $identity = null,
        public ?string $userContext = null,
        public ?string $toolsConfig = null,
        public ?AgentModel $parentAgent = null,
    ) {
    }
}
