<?php

declare(strict_types=1);

namespace App\GraphQL\Intelligence\Types;

use App\GraphQL\Types\MappedUnionTypeResolver;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Users\Models\Users;

class AgentConversationParticipantTypeResolver extends MappedUnionTypeResolver
{
    protected const array MODEL_TO_TYPE_NAME = [
        Users::class => 'User',
        People::class => 'People',
        Agent::class => 'AgentAi',
    ];
}
