<?php

declare(strict_types=1);

namespace App\GraphQL\Intelligence\Queries\Agent;

use Kanvas\Apps\Models\Apps;
use Kanvas\Intelligence\Agents\Actions\Voice\BuildVoiceAgentLeadContextAction;
use Kanvas\Intelligence\Agents\Repositories\AgentsRepository;

/**
 * Outbound call context: given the number being dialed, resolve the lead and
 * return a compact context (who + recent history) for the voice agent. Guarded
 * by @guardByAppKey (server-to-server) and cross-app aware. Null when no lead.
 *
 * @return array<string, mixed>|null
 */
class VoiceAgentLeadContextQuery
{
    public function __invoke(mixed $root, array $args): ?array
    {
        $app = app(Apps::class);
        $agent = AgentsRepository::getByUuidForVoiceRuntime((string) $args['agent_uuid'], $app);

        return new BuildVoiceAgentLeadContextAction($agent, (string) $args['phone'])->execute();
    }
}
