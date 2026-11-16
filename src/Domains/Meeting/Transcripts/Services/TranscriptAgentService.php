<?php

declare(strict_types=1);

namespace Kanvas\Meeting\Transcripts\Services;

use Baka\Support\Str;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Intelligence\Agents\Enums\AgentLlmProviderEnum;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Neuron\Providers\KanvasGeminiTranscribe;
use Kanvas\Intelligence\Agents\Services\AgentProviderService;
use Kanvas\Intelligence\Agents\Services\AttachmentDescriptionService;
use Kanvas\Intelligence\Agents\Services\LlmHttpRetryService;
use Kanvas\Intelligence\Enums\ConfigurationEnum;
use Kanvas\Meeting\Meetings\Models\Meeting;
use Kanvas\Meeting\Transcripts\Agents\TranscriptAgent;
use Kanvas\Meeting\Transcripts\Exceptions\TranscriptProviderException;
use NeuronAI\HttpClient\Guzzle\GuzzleHttpClient;

class TranscriptAgentService
{
    /**
     * One request carries up to 25 minutes of audio; the answer has taken well over the 220 s the chat
     * providers allow.
     */
    private const int HTTP_TIMEOUT = 900;

    /**
     * The company's own agent of the Transcript type, when it hired one to choose the provider.
     */
    public function agentFor(Meeting $meeting): ?Agent
    {
        return Agent::query()
            ->fromApp($meeting->apps_id)
            ->where('companies_id', $meeting->companies_id)
            ->whereHas('type', fn ($query) => $query->where('handler', TranscriptAgent::class))
            ->notDeleted()
            ->orderBy('id')
            ->first();
    }

    /**
     * The company's Transcript agent decides: whatever provider and model it resolves to, the same way
     * any agent's does (its LLM config, then `config.llm_provider` / `config.model`), is what runs.
     * Only what the agent leaves unset falls back: no agent, or a Gemini agent that names no model,
     * runs TranscriptAgent::DEFAULT_MODEL.
     *
     * A transcribe-family Gemini model runs through its own provider; anything else is a chat model
     * held to the agent's instructions, and must be one that takes audio.
     */
    public function handlerFor(Meeting $meeting): TranscriptAgent
    {
        $agent = $this->agentFor($meeting);
        $handler = new TranscriptAgent();
        $provider = $agent !== null ? AgentProviderService::resolveProviderEnum($agent) : AgentLlmProviderEnum::GEMINI;
        $model = $agent !== null ? $this->modelOf($agent, $provider) : TranscriptAgent::DEFAULT_MODEL;

        if ($provider === AgentLlmProviderEnum::GEMINI && KanvasGeminiTranscribe::supports($model)) {
            return $handler->runWith($this->transcribeProvider($meeting, $agent, $model), $provider->value);
        }

        try {
            $chat = AgentProviderService::resolve($agent);
        } catch (ValidationException $e) {
            throw new TranscriptProviderException(
                $e->getMessage(),
                TranscriptProviderException::NOT_CONFIGURED,
                $provider->value,
                $model,
            );
        }

        if (! AttachmentDescriptionService::providerHearsAudio($chat)) {
            throw new TranscriptProviderException(
                'The Transcript agent runs on ' . $provider->value . ', which does not take audio.'
                . ' Point it at Gemini or Mistral.',
                TranscriptProviderException::CANNOT_HEAR_AUDIO,
                $provider->value,
                $model,
            );
        }

        return $handler->runWith($chat, $provider->value);
    }

    private function modelOf(Agent $agent, AgentLlmProviderEnum $provider): string
    {
        $named = AgentProviderService::selectedConfig($agent)?->model ?? $agent->config['model'] ?? null;

        return match (true) {
            Str::trimToNull($named) !== null => (string) $named,
            $provider === AgentLlmProviderEnum::GEMINI => TranscriptAgent::DEFAULT_MODEL,
            default => AgentProviderService::resolveModel($agent),
        };
    }

    private function transcribeProvider(Meeting $meeting, ?Agent $agent, string $model): KanvasGeminiTranscribe
    {
        $key = Str::trimToNull(
            ($agent !== null ? AgentProviderService::selectedConfig($agent)?->api_key ?? $agent->config['key'] ?? null : null)
            ?? $meeting->app->get(ConfigurationEnum::GEMINI_KEY->value)
        );

        if ($key === null) {
            throw new TranscriptProviderException(
                'No Gemini API key to transcribe with. Set the app ' . ConfigurationEnum::GEMINI_KEY->value
                . ' setting, or an LLM config on the company\'s Transcript agent.',
                TranscriptProviderException::NOT_CONFIGURED,
                AgentLlmProviderEnum::GEMINI->value,
                $model,
            );
        }

        $options = $agent?->config['transcription'] ?? [];

        return new KanvasGeminiTranscribe(
            key: $key,
            model: $model,
            diarization: (bool) ($options['diarization'] ?? true),
            wordTimestamps: (bool) ($options['word_timestamps'] ?? false),
            httpClient: new GuzzleHttpClient(
                timeout: self::HTTP_TIMEOUT,
                connectTimeout: 30,
                handler: LlmHttpRetryService::handlerStack(),
            ),
        );
    }
}
