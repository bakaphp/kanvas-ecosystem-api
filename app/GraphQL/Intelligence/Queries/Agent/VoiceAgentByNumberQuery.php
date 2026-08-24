<?php

declare(strict_types=1);

namespace App\GraphQL\Intelligence\Queries\Agent;

use Kanvas\Apps\Models\Apps;
use Kanvas\Intelligence\Agents\Repositories\AgentsRepository;

/**
 * Inbound routing: given the dialed phone number, return which agent should
 * answer. The external voice runtime calls this from its Twilio incoming-call
 * webhook to build TwiML with the right agent_id. Guarded by @guardByAppKey
 * (server-to-server) and cross-app aware (see AgentsRepository).
 *
 * Returns null when no agent claims the number, so the runtime can fall back to
 * a default agent rather than reject the call.
 *
 * @return array<string, mixed>|null
 */
class VoiceAgentByNumberQuery
{
    public function __invoke(mixed $root, array $args): ?array
    {
        $app = app(Apps::class);
        $agent = AgentsRepository::getByPhoneForVoiceRuntime((string) $args['phone_number'], $app);

        return $agent ? ['agent_id' => $agent->uuid] : null;
    }
}
