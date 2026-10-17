<?php

declare(strict_types=1);

namespace Tests\Intelligence\Agents\Neuron;

use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Models\AgentType;
use Kanvas\Users\Models\Users;

class GlobalAgentSummarizationTest extends SummarizationOnConversationStoreTest
{
    protected function makeAgentFor(Users $user, ?AgentType $type = null): Agent
    {
        $agent = parent::makeAgentFor($user, $type);
        $agent->companies_id = 0;
        $agent->saveOrFail();

        return $agent->fresh();
    }
}
