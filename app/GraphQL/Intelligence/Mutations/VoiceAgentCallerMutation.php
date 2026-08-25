<?php

declare(strict_types=1);

namespace App\GraphQL\Intelligence\Mutations;

use Kanvas\Apps\Models\Apps;
use Kanvas\Intelligence\Agents\Actions\Voice\CaptureVoiceCallerAction;
use Kanvas\Intelligence\Agents\Repositories\AgentsRepository;

/**
 * Voice data plane: capture the caller a voice agent is talking to (upsert a
 * People, promote to a Lead on repeat/intent). Server-to-server (@guardByAppKey)
 * and cross-app aware. The phone is supplied by the runtime from call context —
 * never by the LLM.
 */
class VoiceAgentCallerMutation
{
    /**
     * @param array{agent_uuid: string, phone: string, name?: string|null, interest?: string|null, interested?: bool|null} $args
     *
     * @return array<string, mixed>
     */
    public function capture(mixed $root, array $args): array
    {
        $app = app(Apps::class);
        $agent = AgentsRepository::getByUuidForVoiceRuntime((string) $args['agent_uuid'], $app);

        return new CaptureVoiceCallerAction(
            $agent,
            (string) $args['phone'],
            $args['name'] ?? null,
            $args['interest'] ?? null,
            (bool) ($args['interested'] ?? false),
        )->execute();
    }
}
