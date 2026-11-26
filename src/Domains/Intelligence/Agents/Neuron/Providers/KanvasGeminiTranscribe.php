<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Providers;

use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\SystemMessage;
use NeuronAI\HttpClient\HttpClientInterface;
use NeuronAI\Providers\AIProviderInterface;
use Override;

/**
 * Gemini's speech-to-text family (`gemini-*-transcribe`). It is not a chat model: it rejects system
 * instructions ("Developer instruction is not enabled for this model") and JSON mode, ignores the
 * prompt text, and answers with one `audioTranscription` part per speaker turn. The reply is handed
 * back as the JSON a chat model would be asked for, so a caller parses one shape for both.
 */
class KanvasGeminiTranscribe extends KanvasGemini
{
    /**
     * Diarization and word timestamps can be combined, but with word timestamps on the model has
     * returned a whole two-speaker recording as one speaker (measured 2026-11-15), so it is opt-in.
     */
    public function __construct(
        string $key,
        string $model,
        bool $diarization = true,
        bool $wordTimestamps = false,
        ?HttpClientInterface $httpClient = null,
    ) {
        $options = array_filter(['diarization' => $diarization, 'wordTimestamp' => $wordTimestamps]);

        parent::__construct(
            key: $key,
            model: $model,
            parameters: $options === [] ? [] : ['generationConfig' => ['audioTranscriptionConfig' => $options]],
            httpClient: $httpClient,
        );
    }

    public static function supports(string $model): bool
    {
        return str_contains(strtolower($model), 'transcribe');
    }

    #[Override]
    public function systemPrompt(SystemMessage|string|null $prompt): AIProviderInterface
    {
        return $this;
    }

    #[Override]
    protected function processChatResult(array $result): AssistantMessage
    {
        // Silence comes back as a finished candidate with no parts at all: that is "nobody spoke", not
        // a failure. Any other finish reason is left for the parent to judge.
        $candidate = $result['candidates'][0] ?? null;

        if ($candidate !== null && (isset($candidate['content']['parts']) || ($candidate['finishReason'] ?? null) === 'STOP')) {
            $result['candidates'][0]['content'] = [
                'role' => 'model',
                'parts' => [[
                    'text' => json_encode(
                        ['segments' => self::segments($candidate['content']['parts'] ?? [])],
                        JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
                    ),
                ]],
            ];
        }

        return parent::processChatResult($result);
    }

    /**
     * @param array<int, array<string, mixed>> $parts
     * @return list<array{speaker: ?string, text: string, start_ms: ?int, end_ms: ?int}>
     */
    private static function segments(array $parts): array
    {
        $segments = [];

        foreach ($parts as $part) {
            $transcription = $part['audioTranscription'] ?? [];
            $text = trim((string) ($transcription['text'] ?? $part['text'] ?? ''));

            if ($text === '') {
                continue;
            }

            $words = array_values($transcription['words'] ?? []);

            $segments[] = [
                'speaker' => $transcription['speakerLabel'] ?? null,
                'text' => $text,
                'start_ms' => self::milliseconds($words[0]['startOffset'] ?? null),
                'end_ms' => self::milliseconds($words[max(count($words) - 1, 0)]['endOffset'] ?? null),
            ];
        }

        return $segments;
    }

    /**
     * Offsets arrive as decimal seconds with an `s` suffix ("20.700s").
     */
    private static function milliseconds(?string $offset): ?int
    {
        return $offset === null ? null : (int) round((float) rtrim($offset, 's') * 1000);
    }
}
