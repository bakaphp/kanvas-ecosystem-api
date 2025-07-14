<?php

namespace Kanvas\Intelligence\Agents\DataTransferObject;

use Kanvas\ActionEngine\Tasks\Models\TaskList;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Intelligence\Agents\Models\AgentModel;
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
        public string $role,
        public bool $is_active,
        public ?AgentModel $agentModel = null,
        public ?string $description = null,
        public ?string $config = null,
        public ?TaskList $task = null,
        public array $communicationChannel = []
    ) {
    }
}
