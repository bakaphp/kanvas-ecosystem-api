<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Services;

use Baka\Contracts\AppInterface;
use Kanvas\Companies\Models\Companies;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Enums\ConfigurationEnum;

/**
 * Compiles an Agent into the "voice agent spec" the external voice runtime
 * (Pipecat / Cloud Run) fetches at the start of each call.
 *
 * This is the read-only config-plane compilation: it assembles the same system
 * prompt the in-process NeuronAI agent uses (soul → instructions →
 * output_format, with AgentType fallback — see Agents\Types\BaseAgent), the
 * resolved model name, the per-agent voice_config, and the company's telephony
 * number. It never returns credentials — Twilio secrets stay out of this payload.
 */
class VoiceAgentSpecService
{
    public function __construct(
        private readonly Agent $agent,
        private readonly AppInterface $app,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function compile(): array
    {
        $voice = $this->agent->voice_config ?? [];
        $tts = $voice['tts'] ?? [];
        $stt = $voice['stt'] ?? [];
        $language = $voice['language'] ?? 'es';

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
                'llm' => [
                    'provider' => 'google',
                    'model' => $this->resolvedModelName(),
                ],
                'tts' => [
                    'provider' => $tts['provider'] ?? 'elevenlabs',
                    'voice_id' => $tts['voice_id'] ?? ($voice['voice_id'] ?? null),
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
        ];
    }

    /**
     * Same coalescing as Agents\Types\BaseAgent::instructions(): prefer the
     * per-field prompt on the agent, fall back per-field to the AgentType so a
     * type acts as the base persona, then legacy structured `role`.
     */
    private function systemInstruction(): string
    {
        $type = $this->agent->type;
        $coalesce = static fn (?string $a, ?string $b): ?string => ($a !== null && $a !== '') ? $a : $b;

        $parts = array_filter([
            $coalesce($this->agent->soul, $type?->soul),
            $coalesce($this->agent->instructions, $type?->instructions),
            $coalesce($this->agent->output_format, $type?->output_format),
        ]);

        if ($parts !== []) {
            return implode("\n\n", $parts);
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
     * Per-agent model override → app default → hardcoded default. Mirrors
     * BaseKanvasAgent::resolvedModelName().
     */
    private function resolvedModelName(): string
    {
        $config = $this->agent->config ?? [];

        if (! empty($config['model'])) {
            return (string) $config['model'];
        }

        $appModel = $this->app->get(ConfigurationEnum::GEMINI_MODEL->value);

        return $appModel !== null && $appModel !== '' ? (string) $appModel : 'gemini-2.5-flash';
    }

    /**
     * The dealership's outbound caller-id number, read from company settings.
     * Credentials (account sid / auth token) are deliberately NOT included here.
     *
     * @return array<string, mixed>
     */
    private function telephony(): array
    {
        // Load the owning company defensively — find() returns null instead of
        // throwing on a missing/invalid companies_id, so a telephony problem can
        // never 503 the whole spec fetch. Read the number straight off the
        // company's `phone` column (not the settings store).
        $company = $this->agent->companies_id > 0
            ? Companies::find($this->agent->companies_id)
            : null;

        return [
            'from_number' => $company?->phone,
        ];
    }

    private function floatOrNull(mixed $value): ?float
    {
        return $value === null ? null : (float) $value;
    }
}
