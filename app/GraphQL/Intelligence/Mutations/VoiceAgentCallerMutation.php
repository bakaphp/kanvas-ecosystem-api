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
     * @param array{agent_uuid: string, phone: string, name?: string|null, interest?: string|null, interested?: bool|null, direction?: string|null} $args
     *
     * @return array<string, mixed>
     */
    public function capture(mixed $root, array $args): array
    {
        $app = app(Apps::class);
        $agent = AgentsRepository::getByUuidForVoiceRuntime((string) $args['agent_uuid'], $app);

        return new CaptureVoiceCallerAction(
            agent: $agent,
            phone: (string) $args['phone'],
            name: $args['name'] ?? null,
            interest: $args['interest'] ?? null,
            interested: (bool) ($args['interested'] ?? false),
            direction: $args['direction'] ?? null,
            summary: $args['summary'] ?? null,
            wantsAppointment: (bool) ($args['wants_appointment'] ?? false),
            appointmentPreference: $args['appointment_preference'] ?? null,
        )->execute();
    }
}
