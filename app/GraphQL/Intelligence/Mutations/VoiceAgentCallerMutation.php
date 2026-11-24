<?php

declare(strict_types=1);

namespace App\GraphQL\Intelligence\Mutations;

use App\GraphQL\Intelligence\Queries\Agent\VoiceAgentTwilioQuery;
use Baka\Traits\KanvasJobsTrait;
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
    use KanvasJobsTrait;

    /**
     * @param array{agent_uuid: string, phone: string, name?: string|null, interest?: string|null, interested?: bool|null, direction?: string|null, summary?: string|null, wants_appointment?: bool|null, appointment_preference?: string|null} $args
     *
     * @return array<string, mixed>
     */
    public function capture(mixed $root, array $args): array
    {
        $agent = AgentsRepository::getByUuidForVoiceRuntime((string) $args['agent_uuid'], app(Apps::class));
        // A cross-app runtime key (VOICE_RUNTIME_CROSS_APP) resolves agents of other apps, and the
        // request is bound to the key's app: custom-field definitions go to the bound app.
        $this->overwriteAppService($agent->app);

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

    /**
     * @param array{agent_uuid: string, phone: string, call_sid: string, direction?: string|null, turns: list<array{role: string, text: string}>, summary?: string|null} $args
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

        // A cross-app runtime key resolves agents of other apps: the role behind the lead and person
        // channels is looked up in the scope of the agent's app.
        $this->overwriteAppService($agent->app);

        return new RecordVoiceTranscriptAction(
            agent: $agent,
            phone: (string) $args['phone'],
            callSid: (string) $args['call_sid'],
            turns: $args['turns'],
            direction: $args['direction'] ?? null,
            summary: $args['summary'] ?? null,
        )->execute();
    }
}
