<?php

declare(strict_types=1);

namespace App\GraphQL\Intelligence\Queries\Agent;

use Kanvas\Apps\Models\Apps;
use Kanvas\Intelligence\Agents\Repositories\AgentsRepository;
use Kanvas\Intelligence\Agents\Services\VoiceAgentSpecService;

/**
 * Returns the compiled voice-agent spec for the external voice runtime to apply
 * at the start of a call. Guarded by @guardByAppKey (server-to-server), so the
 * runtime authenticates with the X-Kanvas-App + X-Kanvas-App-Key headers.
 *
 * @return array<string, mixed>
 */
class VoiceAgentSpecQuery
{
    public function __invoke(mixed $root, array $args): array
    {
        $app = app(Apps::class);
        $agent = AgentsRepository::getByUuidForVoiceRuntime((string) $args['uuid'], $app);

        return new VoiceAgentSpecService($agent, $app)->compile();
    }
}
