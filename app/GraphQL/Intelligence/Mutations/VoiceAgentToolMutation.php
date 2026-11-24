<?php

declare(strict_types=1);

namespace App\GraphQL\Intelligence\Mutations;

use Kanvas\Apps\Models\Apps;
use Kanvas\Intelligence\Agents\Actions\Voice\RunVoiceAgentToolAction;
use Kanvas\Intelligence\Agents\Repositories\AgentsRepository;

/**
 * Voice-agent data plane resolver. Server-to-server (@guardByAppKey) and
 * cross-app aware (via getByUuidForVoiceRuntime): the external voice runtime
 * calls this to execute one of an agent's tools when the LLM makes a function
 * call. Returns the tool's JSON result (Mixed).
 */
class VoiceAgentToolMutation
{
    /**
     * @param array{agent_uuid: string, tool_name: string, arguments?: mixed} $args
     */
    public function run(mixed $root, array $args): mixed
    {
        $app = app(Apps::class);
        $agent = AgentsRepository::getByUuidForVoiceRuntime((string) $args['agent_uuid'], $app);

        $arguments = $args['arguments'] ?? [];
        if (! is_array($arguments)) {
            $arguments = [];
        }

        return new RunVoiceAgentToolAction($agent, (string) $args['tool_name'], $arguments)->execute();
    }
}
