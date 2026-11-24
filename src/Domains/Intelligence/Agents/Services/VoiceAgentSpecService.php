<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Services;

use Baka\Support\Str;
use Kanvas\Connectors\Twilio\Enums\ConfigurationEnum as TwilioConfigurationEnum;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Enums\ConfigurationEnum;
use Kanvas\NervousSystem\Capability\Enums\CapabilityFrameworkEnum;
use Kanvas\NervousSystem\Capability\Services\CapabilityProvider;
use NeuronAI\Tools\Tool as NeuronTool;
use stdClass;
use Throwable;

/**
 * Compiles an Agent into the "voice agent spec" the external voice runtime
 * (Pipecat / Cloud Run) fetches at the start of each call.
 *
 * This is the read-only config-plane compilation: it assembles the agent's
 * persona prompt (Agent::personaPrompt(), the legacy structured `role` as the
 * fallback), the resolved model name, the per-agent voice_config, and the agent's telephony
 * number (per-agent, falling back to the company). It never returns credentials
 * — Twilio secrets stay out of this payload.
 */
class VoiceAgentSpecService
{
    public function __construct(private readonly Agent $agent)
    {
    }

    /**
     * @return array<string, mixed>
     */
    public function compile(): array
    {
        $voice = $this->agent->voice_config ?? [];
        $tts = $voice['tts'] ?? [];
        $stt = $voice['stt'] ?? [];
        $language = Str::trimToNull((string) ($voice['language'] ?? '')) ?? 'en';

        return [
            'agent_id' => $this->agent->uuid,
            // Changes whenever the agent is edited, so the runtime can cache by
            // agent_id:version and pick up updates on the next call.
            'version' => (string) ($this->agent->updated_at?->getTimestamp() ?? $this->agent->id),
            'language' => $language,
            'prompts' => [
                'system_instruction' => $this->systemInstruction(),
                'greeting' => $voice['greeting'] ?? null,
                'voicemail_message' => $voice['voicemail_message'] ?? null,
            ],
            'models' => [
                'stt' => [
                    'provider' => $stt['provider'] ?? 'deepgram',
                    'model' => $stt['model'] ?? null,
                    'language' => $stt['language'] ?? $language,
                ],
                'llm' => $this->llm(),
                'tts' => [
                    'provider' => $tts['provider'] ?? 'elevenlabs',
                    'voice_id' => self::ownVoiceId($voice) ?? VoiceRuntimeConfig::defaultVoiceId(),
                    'model' => $tts['model'] ?? null,
                    'stability' => $this->floatOrNull($tts['stability'] ?? null),
                    'similarity' => $this->floatOrNull($tts['similarity'] ?? null),
                    'style' => $this->floatOrNull($tts['style'] ?? null),
                    'speed' => $this->floatOrNull($tts['speed'] ?? null),
                    'use_speaker_boost' => $tts['use_speaker_boost'] ?? null,
                ],
            ],
            'telephony' => $this->telephony(),
            'context_schema' => array_values($voice['context_schema'] ?? []),
            'tools' => $this->tools(),
        ];
    }

    /**
     * The voice the agent itself picked (voice_config.tts.voice_id, or the older top-level
     * voice_id), or null when it picked none.
     *
     * @param array<string, mixed> $voice
     */
    public static function ownVoiceId(array $voice): ?string
    {
        return Str::trimToNull((string) ($voice['tts']['voice_id'] ?? $voice['voice_id'] ?? ''));
    }

    /**
     * A handler that can't be built is skipped, never fatal. RunVoiceAgentToolAction builds through
     * here too, so the tools a call can run are the ones this spec advertised.
     */
    public static function buildHandler(string $handlerClass): ?NeuronTool
    {
        try {
            $handler = app($handlerClass);
        } catch (Throwable) {
            return null;
        }

        return $handler instanceof NeuronTool ? $handler : null;
    }

    /**
     * The agent's NEURON tools, for the voice runtime to register as function-calling tools.
     *
     * @return array<int, array<string, mixed>>
     */
    private function tools(): array
    {
        $tools = [];
        $catalogTools = new CapabilityProvider()
            ->getActiveTools($this->agent, CapabilityFrameworkEnum::NEURON->value);

        foreach ($catalogTools as $catalogTool) {
            $handlerClass = $catalogTool->handlerClass();
            $handler = $handlerClass !== null ? self::buildHandler($handlerClass) : null;
            if ($handler === null) {
                continue;
            }

            $properties = [];
            foreach ($handler->getProperties() as $property) {
                $properties[$property->getName()] = $property->getJsonSchema();
            }

            $tools[] = [
                'name' => $handler->getName(),
                'description' => $handler->getDescription(),
                'parameters' => [
                    'type' => 'object',
                    // stdClass so an empty set serializes as JSON {}, not [].
                    'properties' => $properties === [] ? new stdClass() : $properties,
                    'required' => $handler->getRequiredProperties(),
                ],
                'read_only' => AgentTool::isReadOnly($handler),
            ];
        }

        return $tools;
    }

    /**
     * The agent's persona prompt, then the legacy structured `role` for records that predate it.
     */
    public function systemInstruction(): string
    {
        $persona = $this->agent->personaPrompt();

        if ($persona !== '') {
            return $persona;
        }

        $role = $this->agent->role;
        if (is_array($role) && isset($role['background'])) {
            return trim(implode("\n", array_merge(
                (array) $role['background'],
                (array) ($role['steps'] ?? []),
                (array) ($role['output'] ?? []),
            )));
        }

        return '';
    }

    /**
     * The call's LLM: the agent's voice LLM config when one is set — provider, model and base URL,
     * never its API key (the runtime holds its own provider keys) — otherwise Gemini as before.
     * The chat config (agent_llm_config_id) deliberately doesn't apply to calls.
     *
     * @return array{provider: string, model: string, base_url: string|null}
     */
    private function llm(): array
    {
        $config = AgentProviderService::voiceConfig($this->agent);
        if ($config === null || empty($config->model)) {
            return ['provider' => 'google', 'model' => $this->resolvedModelName(), 'base_url' => null];
        }

        return [
            'provider' => (string) $config->provider,
            'model' => (string) $config->model,
            'base_url' => $config->base_uri ?: null,
        ];
    }

    /**
     * Gemini model for agents without a voice LLM config: per-agent override → app default →
     * hardcoded default.
     */
    private function resolvedModelName(): string
    {
        $config = $this->agent->config ?? [];

        if (! empty($config['model'])) {
            return (string) $config['model'];
        }

        $appModel = $this->agent->app->get(ConfigurationEnum::GEMINI_MODEL->value);

        return $appModel !== null && $appModel !== '' ? (string) $appModel : 'gemini-2.5-flash';
    }

    /**
     * The outbound caller-id number for this agent. Preference order:
     *   1. the per-agent number set in voice_config.phone_number (admin UI)
     *   2. the owning company's Twilio from-number setting
     *   3. the company's `phone` column
     * Credentials (account sid / auth token) are deliberately NOT included here.
     *
     * @return array<string, mixed>
     */
    private function telephony(): array
    {
        // A number configured on the agent itself wins over the company default,
        // so each agent can have its own caller id. Blank/unset falls through.
        $voice = $this->agent->voice_config ?? [];
        $agentNumber = $voice['phone_number'] ?? null;

        // Null for an agent with no company, so a telephony gap never fails the whole spec fetch.
        $company = $this->agent->company;

        $fromNumber = ($agentNumber !== null && $agentNumber !== '')
            ? $agentNumber
            : ($company?->get(TwilioConfigurationEnum::TWILIO_FROM_PHONE_NUMBER->value)
                ?? $company?->phone);

        return [
            'from_number' => $fromNumber,
        ];
    }

    private function floatOrNull(mixed $value): ?float
    {
        return $value === null ? null : (float) $value;
    }
}
