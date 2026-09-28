<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Supadata;

use Illuminate\Support\Sleep;
use Kanvas\Connectors\Supadata\Client;
use Kanvas\Connectors\Supadata\Exceptions\TranscriptUnavailableException;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\ResolvesSupadataClientForTool;
use NeuronAI\Tools\HasRunKey;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use NeuronAI\Tools\TrackByInputs;
use Override;
use Throwable;

/**
 * Reading a recording is the difference between a PM that manages what people remembered to type and
 * one that manages what was actually said — a standup on YouTube, a demo Reel, a call uploaded to a
 * file URL. Supadata fetches the platform's own captions when they exist and transcribes with AI when
 * they do not, so one tool covers both without the agent having to know which case it is in.
 *
 * The attribute carries its own description, which displaces the runtime one in the catalog, because
 * the catalog answers a different question: whoever grants this tool needs to know it costs money and
 * needs a key, which is noise to the model mid-turn. It still names the platforms, because
 * `capability_lookup` matches on this text and a search for "tiktok transcript" has to reach it.
 */
#[AgentTool(
    name: 'Get Transcription',
    category: 'knowledge',
    description: 'Transcribes a video or audio recording from its link — YouTube, TikTok, Instagram, '
        . 'X (Twitter), Facebook, or any public audio/video file URL — so an agent can read, summarize '
        . 'or pull action items out of a meeting, call, demo or standup it was only given a link to. '
        . 'Reads the existing platform captions/subtitles when there are any and generates a '
        . 'transcript with AI when there are not. Requires a Supadata API key on the company (falling '
        . 'back to the app), and BILLS PER MINUTE of media when it has to generate — grant it where '
        . 'recordings are part of the work, not as a default. Long media returns a job the agent '
        . 'collects on a second call.',
)]
class GetTranscriptionTool extends Tool implements HasRunKey
{
    use ResolvesSupadataClientForTool;
    use TrackByInputs;

    private const int MAX_CONTENT_LENGTH = 25000;

    /**
     * Long media comes back as a job. Waiting a little inside the call is what keeps the common case
     * a single tool call; waiting a lot would burn the turn, so the budget stops well short of a
     * timeout and hands the job id back for the model to resume with.
     */
    private const int POLL_ATTEMPTS = 5;
    private const int POLL_INTERVAL_SECONDS = 3;

    public function __construct()
    {
        parent::__construct(
            name: 'get_transcription',
            description: 'Read what was said in a video or audio recording, given its link. Works with '
                . 'YouTube, TikTok, Instagram, X (Twitter), Facebook, and any public file URL of an audio '
                . 'or video file. Use it whenever someone shares a recording and asks what is in it, or '
                . 'asks you to summarize, extract decisions, or pull action items out of a call, demo or '
                . 'standup. It reads existing captions when the platform has them and transcribes the '
                . 'audio when it does not. Long recordings come back as a job_id — call again with that '
                . 'job_id to collect the result.',
        );
    }

    /**
     * @return array<int, ToolProperty>
     */
    #[Override]
    protected function properties(): array
    {
        return [
            new ToolProperty(
                name: 'url',
                type: PropertyType::STRING,
                description: 'Full link to the recording, including the scheme — e.g. '
                    . '"https://youtu.be/dQw4w9WgXcQ". Required unless you are resuming with a job_id.',
                required: false,
            ),
            new ToolProperty(
                name: 'lang',
                type: PropertyType::STRING,
                description: 'Preferred transcript language as an ISO 639-1 code, e.g. "en" or "es". '
                    . 'Leave it off to take whatever language the recording is in — passing one the '
                    . 'recording does not have falls back to the first available.',
                required: false,
            ),
            new ToolProperty(
                name: 'include_timestamps',
                type: PropertyType::BOOLEAN,
                description: 'Prefix every line with its position in the recording. Ask for it only when '
                    . 'you need to cite or jump to a moment — it makes the transcript noticeably longer, '
                    . 'so plain text is the better default for summarizing.',
                required: false,
            ),
            new ToolProperty(
                name: 'mode',
                type: PropertyType::STRING,
                description: 'Use "native" to only read captions the platform already has, which is free '
                    . 'of transcription cost but returns nothing when there are none. "generate" always '
                    . 'transcribes the audio. Defaults to "auto", which tries native first.',
                required: false,
                enum: ['native', 'auto', 'generate'],
            ),
            new ToolProperty(
                name: 'job_id',
                type: PropertyType::STRING,
                description: 'Only when resuming: the job_id a previous call returned for a long '
                    . 'recording. Pass it on its own to collect that transcript instead of starting a new one.',
                required: false,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(
        ?string $url = null,
        ?string $lang = null,
        ?bool $include_timestamps = null,
        ?string $mode = null,
        ?string $job_id = null,
    ): array {
        $url = $this->optionalText($url);
        $jobId = $this->optionalText($job_id);
        $mode = $this->optionalText($mode) ?? 'auto';

        if ($url === null && $jobId === null) {
            return ['error' => 'Pass the link to the recording as "url", or a "job_id" from a previous '
                . 'call to collect a transcript that was still processing.'];
        }

        if ($url !== null && $jobId === null) {
            $invalid = $this->rejectInvalidUrl($url);
            if ($invalid !== null) {
                return $invalid;
            }
        }

        $client = $this->resolveSupadataClientOrError();
        if (is_array($client)) {
            return $client;
        }

        $withTimestamps = $include_timestamps === true;

        try {
            $response = $jobId !== null
                ? $client->transcriptJob($jobId)
                : $client->transcript((string) $url, array_filter(
                    [
                        'text' => ! $withTimestamps,
                        'mode' => $mode,
                        'lang' => $this->optionalText($lang),
                    ],
                    static fn (mixed $value): bool => $value !== null,
                ));

            $resolved = $this->awaitJob($client, $response);
        } catch (TranscriptUnavailableException $e) {
            // Ordinary outcome of asking about a recording, not a fault — answering with it keeps
            // Sentry for the failures somebody has to act on.
            return ['error' => 'There is no transcript for this recording and one could not be made: '
                . $e->getMessage() . ' Do not retry the same link'
                . ($mode === 'native' ? ' — try again without mode "native" to transcribe the audio.' : '.')];
        } catch (Throwable $e) {
            report($e);

            return ['error' => 'Getting the transcript failed: ' . $e->getMessage()];
        }

        if (isset($resolved['jobId'])) {
            return [
                'status' => 'processing',
                'job_id' => (string) $resolved['jobId'],
                'message' => 'This recording is long enough that it is still being transcribed. Tell the '
                    . 'user it is in progress, then call this tool again with this job_id to collect it.',
            ];
        }

        if (($resolved['status'] ?? null) === 'failed') {
            return ['error' => 'Supadata could not transcribe this recording: '
                . (string) ($resolved['error'] ?? 'no reason given') . ' Do not retry the same link.'];
        }

        return $this->presentTranscript($resolved, $url, $withTimestamps);
    }

    /**
     * Poll a job to completion within the budget, or hand back the `{jobId}` envelope untouched so the
     * caller can return it to the model.
     *
     * @param array<string, mixed> $response
     * @return array<string, mixed>
     */
    private function awaitJob(Client $client, array $response): array
    {
        $jobId = isset($response['jobId']) ? (string) $response['jobId'] : null;

        if ($jobId === null) {
            return $response;
        }

        for ($attempt = 0; $attempt < self::POLL_ATTEMPTS; $attempt++) {
            Sleep::for(self::POLL_INTERVAL_SECONDS)->seconds();

            $result = $client->transcriptJob($jobId);
            $status = $result['status'] ?? null;

            if ($status === 'completed' || $status === 'failed') {
                return $result;
            }
        }

        return ['jobId' => $jobId];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function presentTranscript(array $payload, ?string $url, bool $withTimestamps): array
    {
        $content = $payload['content'] ?? '';
        $text = is_array($content)
            ? $this->flattenSegments($content, $withTimestamps)
            : (string) $content;

        if (trim($text) === '') {
            return ['error' => 'The recording was processed but no speech was found in it. Say so rather '
                . 'than guessing at the content, and do not retry the same link.'];
        }

        return array_filter([
            'status' => 'success',
            'url' => $url,
            'lang' => isset($payload['lang']) ? (string) $payload['lang'] : null,
            'available_langs' => $payload['availableLangs'] ?? null,
            'transcript' => $this->truncateContent($text, self::MAX_CONTENT_LENGTH),
        ], static fn (mixed $value): bool => $value !== null);
    }

    /**
     * @param array<array-key, mixed> $segments
     */
    private function flattenSegments(array $segments, bool $withTimestamps): string
    {
        $lines = array_map(
            function (mixed $segment) use ($withTimestamps): string {
                $segment = is_array($segment) ? $segment : [];
                $text = trim((string) ($segment['text'] ?? ''));

                if (! $withTimestamps || $text === '') {
                    return $text;
                }

                return '[' . $this->formatOffset((int) ($segment['offset'] ?? 0)) . '] ' . $text;
            },
            $segments,
        );

        return implode("\n", array_filter($lines, static fn (string $line): bool => $line !== ''));
    }

    /**
     * Milliseconds into `h:mm:ss`, dropping the hour on anything shorter — a `0:03:12` on a four
     * minute clip is a timestamp the model has to parse rather than read.
     */
    private function formatOffset(int $milliseconds): string
    {
        $seconds = intdiv(max($milliseconds, 0), 1000);
        $hours = intdiv($seconds, 3600);

        return $hours > 0
            ? sprintf('%d:%02d:%02d', $hours, intdiv($seconds % 3600, 60), $seconds % 60)
            : sprintf('%d:%02d', intdiv($seconds, 60), $seconds % 60);
    }
}
