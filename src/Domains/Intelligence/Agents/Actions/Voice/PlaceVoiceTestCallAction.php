<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Actions\Voice;

use Illuminate\Support\Facades\Http;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Services\VoiceRuntimeConfig;
use Throwable;

use function Sentry\captureException;

/**
 * Place a one-off TEST outbound call for an agent through the external voice
 * runtime (Pipecat / Cloud Run). This is the config-plane trigger behind the
 * admin UI's "Test call" button: it POSTs to the runtime's `POST /outbound`,
 * which dials the number and streams the answered call to this agent. The agent
 * speaks with its own voice config and dials FROM its own number
 * (voice_config.phone_number → the spec's telephony.from_number).
 *
 * The runtime endpoint + bearer token come from the environment
 * (VOICE_RUNTIME_URL / VOICE_RUNTIME_API_TOKEN, see VoiceRuntimeConfig).
 */
class PlaceVoiceTestCallAction
{
    // E.164: leading +, first digit 1-9, up to 15 digits total.
    private const E164 = '/^\+[1-9]\d{6,14}$/';

    public function __construct(
        private readonly Agent $agent,
        private readonly string $toNumber,
    ) {
    }

    /**
     * @return array<string, mixed> the runtime's call record (call_id, twilio_sid,
     *                              status, to, tenant_id)
     */
    public function execute(): array
    {
        if (! preg_match(self::E164, $this->toNumber)) {
            throw new ValidationException(
                "The number to call must be in E.164 format, e.g. +15551234567 (got '{$this->toNumber}')."
            );
        }

        $url = VoiceRuntimeConfig::url();
        $token = VoiceRuntimeConfig::apiToken();

        if ($url === '' || $token === '') {
            throw new ValidationException(
                'The voice runtime is not configured. Set VOICE_RUNTIME_URL and VOICE_RUNTIME_API_TOKEN in the environment.'
            );
        }

        try {
            $response = Http::withToken($token)
                ->acceptJson()
                ->timeout(20)
                ->post(rtrim($url, '/') . '/outbound', [
                    'to' => $this->toNumber,
                    // The runtime requires a tenant id; the company uuid keeps the
                    // call attributable without leaking internal ids.
                    'tenant_id' => $this->agent->company->uuid,
                    'run_spec' => [
                        'agent_id' => $this->agent->uuid,
                    ],
                ]);
        } catch (Throwable $e) {
            // Network / DNS / timeout reaching the runtime — track it, surface calm copy.
            captureException($e);

            throw new ValidationException('Could not reach the voice runtime: ' . $e->getMessage());
        }

        if ($response->failed()) {
            // Surface the runtime's own error detail (FastAPI sends `detail`).
            $detail = $response->json('detail') ?? $response->body();
            $detail = is_array($detail) ? json_encode($detail) : (string) $detail;

            throw new ValidationException(
                "The voice runtime rejected the call (HTTP {$response->status()}): {$detail}"
            );
        }

        $call = $response->json() ?? [];
        $this->recordTestCall($call);

        return $call;
    }

    /**
     * The readiness check reads this. A quiet write: it is system metadata, not an edit of the agent.
     *
     * @param array<string, mixed> $call
     */
    private function recordTestCall(array $call): void
    {
        $voice = $this->agent->voice_config ?? [];
        $voice['last_test_call'] = [
            'at' => now()->toIso8601String(),
            'call_id' => $call['call_id'] ?? null,
        ];

        $this->agent->voice_config = $voice;
        $this->agent->saveQuietly();
    }
}
