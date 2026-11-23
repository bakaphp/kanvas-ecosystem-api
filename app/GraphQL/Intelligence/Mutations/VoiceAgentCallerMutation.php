<?php

declare(strict_types=1);

namespace App\GraphQL\Intelligence\Mutations;

use App\GraphQL\Intelligence\Queries\Agent\VoiceAgentTwilioQuery;
use Kanvas\Apps\Models\Apps;
use Kanvas\Intelligence\Agents\Actions\Voice\CaptureVoiceCallerAction;
use Kanvas\Intelligence\Agents\Actions\Voice\RecordVoiceTranscriptAction;
use Kanvas\Intelligence\Agents\Repositories\AgentsRepository;
use Kanvas\Intelligence\Agents\Services\VoiceRuntimeConfig;
use Nuwave\Lighthouse\Exceptions\AuthorizationException;

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
            $agent,
            (string) $args['phone'],
            $args['name'] ?? null,
            $args['interest'] ?? null,
            (bool) ($args['interested'] ?? false),
            $args['direction'] ?? null,
            $args['summary'] ?? null,
            (bool) ($args['wants_appointment'] ?? false),
            $args['appointment_preference'] ?? null,
        )->execute();
    }

    /**
     * @param array{agent_uuid: string, phone: string, call_sid: string, direction?: string|null, turns: list<array{role: string, text: string}>} $args
     *
     * @return array<string, mixed>
     */
    public function recordTranscript(mixed $root, array $args): array
    {
        // The app key alone is not enough: a transcript is customer data written on any company's
        // lead, so the runtime also proves who it is.
        if (! VoiceRuntimeConfig::acceptsToken(request()->header(VoiceAgentTwilioQuery::TOKEN_HEADER))) {
            throw new AuthorizationException('Only the voice runtime can record call transcripts.');
        }

        $agent = AgentsRepository::getByUuidForVoiceRuntime((string) $args['agent_uuid'], app(Apps::class));

        return new RecordVoiceTranscriptAction(
            agent: $agent,
            phone: (string) $args['phone'],
            callSid: (string) $args['call_sid'],
            turns: $args['turns'],
            direction: $args['direction'] ?? null,
        )->execute();
    }
}
