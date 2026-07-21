<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Services;

use Baka\Http\SafeUrlFetcher;
use finfo;
use Kanvas\Filesystem\Models\Filesystem;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Neuron\BaseKanvasAgent;
use Kanvas\Users\Models\Users;
use NeuronAI\Chat\Enums\SourceType;
use NeuronAI\Chat\Messages\ContentBlocks\AudioContent;
use NeuronAI\Chat\Messages\ContentBlocks\ContentBlockInterface;
use NeuronAI\Chat\Messages\ContentBlocks\FileContent;
use NeuronAI\Chat\Messages\ContentBlocks\ImageContent;
use NeuronAI\Chat\Messages\ContentBlocks\TextContent;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Providers\AIProviderInterface;
use Throwable;

/**
 * Turns an attachment (image / audio / PDF) into one short text description using the SAME
 * provider/model the agent runs on, so the agent's text-only chat history can "remember" what an
 * attachment was on later turns (the live turn sees the real bytes via a content block; rebuilt
 * history only carries text). One raw provider->chat() call — no tools, no agent system prompt —
 * keeps it cheap and side-effect free. The description is type-tailored: caption an image,
 * transcribe audio, summarize a PDF.
 */
class AttachmentDescriptionService
{
    private const int MAX_DESCRIPTION_LENGTH = 600;

    /**
     * Ceiling for a text/CSV attachment embedded inline as a TextContent block. Text files carry no
     * native-multimodal cap the way images do, so a runaway CSV would otherwise blow the context
     * window (and the token bill). Past this we truncate with a marker.
     */
    public const int MAX_TEXT_ATTACHMENT_BYTES = 200_000;

    public function __construct(
        private readonly AIProviderInterface $provider,
    ) {
    }

    /**
     * Build the describer from an Agent by reusing its configured Neuron provider. Returns null
     * when the agent isn't Neuron-backed (runtime/ADK agents describe nothing here).
     */
    public static function forAgent(Agent $agent, Users $user): ?self
    {
        $handlerClass = $agent->type?->handler;

        if (! is_string($handlerClass) || $handlerClass === '' || ! class_exists($handlerClass)) {
            return null;
        }

        $handler = new $handlerClass();

        if (! $handler instanceof BaseKanvasAgent) {
            return null;
        }

        $handler->setConfiguration(agent: $agent, user: $user);

        return new self($handler->captionProvider());
    }

    /**
     * The native multimodal kind a MIME type maps to, or null when the model can't take it as a
     * content block (docx/xlsx, video, etc. — those stay URL-in-prompt). Images, audio and PDF ride
     * as binary blocks the model reads natively; text/CSV rides as a TextContent block (finfo reports
     * CSV/TSV as text/plain, so we key on the whole text/* family plus the odd application/csv label).
     */
    public static function nativeKind(string $mimeType): ?string
    {
        return match (true) {
            str_starts_with($mimeType, 'image/') => 'image',
            str_starts_with($mimeType, 'audio/') => 'audio',
            $mimeType === 'application/pdf' => 'pdf',
            str_starts_with($mimeType, 'text/'), $mimeType === 'application/csv' => 'text',
            default => null,
        };
    }

    /**
     * Wrap a text/CSV attachment's raw bytes for inline inclusion as a TextContent block, capped so a
     * large file can't blow the context window. Shared by the live-turn runner and the memory describer
     * so both embed text the same way.
     */
    public static function wrapTextForBlock(string $binary, string $mimeType): string
    {
        $text = $binary;

        if (strlen($text) > self::MAX_TEXT_ATTACHMENT_BYTES) {
            $text = substr($text, 0, self::MAX_TEXT_ATTACHMENT_BYTES) . "\n… [truncated]";
        }

        return "Attached file ({$mimeType}):\n\n{$text}";
    }

    /**
     * The attachment kinds the agent's multimodal model can describe (image / audio / PDF) — the
     * same set this service captions and the runners send natively.
     */
    public static function isDescribableFile(Filesystem $file): bool
    {
        $mediaType = $file->mediaType();

        return $mediaType->isImage() || $mediaType->isAudio() || $mediaType->isDocument();
    }

    /**
     * Describe each URL, preserving order. A failed fetch/describe yields '' for that slot so the
     * result stays index-aligned with the input — callers decide whether to keep the empties.
     *
     * @param list<string> $urls
     * @param list<string> $filenames Optional original filenames, index-aligned with $urls, so the
     *                                description can say WHICH file it is (the URL is often a hash).
     * @return list<string>
     */
    public function describeUrls(array $urls, array $filenames = []): array
    {
        $urls = array_values($urls);
        $filenames = array_values($filenames);

        return array_map(
            fn (string $url, int $i): string => $this->describeUrl($url, $filenames[$i] ?? null),
            $urls,
            array_keys($urls),
        );
    }

    public function describeUrl(string $url, ?string $filename = null): string
    {
        try {
            $binary = SafeUrlFetcher::fetch($url);

            if ($binary === '') {
                return '';
            }

            $mimeType = $this->detectMimeType($binary);
            $block = $this->buildContentBlock($binary, $mimeType);

            if ($block === null) {
                return '';
            }

            $message = new UserMessage($this->promptFor($mimeType));
            $message->addContent($block);

            $response = $this->provider->chat($message);
            $description = $this->normalize((string) ($response->getContent() ?? ''));

            if ($description === '') {
                return '';
            }

            // Lead with the type (and filename when known) so the agent can tell an image from a
            // PDF and reference "the file you sent" — e.g. `PDF "receipt.pdf": <summary>`.
            return $this->label($mimeType, $filename) . ': ' . $description;
        } catch (Throwable $e) {
            report($e);

            return '';
        }
    }

    private function label(string $mimeType, ?string $filename): string
    {
        $kind = match (self::nativeKind($mimeType)) {
            'image' => 'Image',
            'audio' => 'Audio',
            'pdf' => 'PDF',
            'text' => 'File',
            default => 'File',
        };

        $name = $filename !== null ? trim($filename) : '';

        return $name !== '' ? "{$kind} \"{$name}\"" : $kind;
    }

    private function buildContentBlock(string $binary, string $mimeType): ?ContentBlockInterface
    {
        $base64 = base64_encode($binary);

        return match (self::nativeKind($mimeType)) {
            'image' => new ImageContent($base64, SourceType::BASE64, $mimeType),
            'audio' => new AudioContent($base64, SourceType::BASE64, $mimeType),
            'pdf' => new FileContent($base64, SourceType::BASE64, $mimeType),
            'text' => new TextContent(self::wrapTextForBlock($binary, $mimeType)),
            default => null,
        };
    }

    private function promptFor(string $mimeType): string
    {
        return match (self::nativeKind($mimeType)) {
            'audio' => 'Transcribe this audio for an assistant\'s memory. If it is speech, give the '
                . 'transcript; otherwise describe the sound in one sentence. Output only the text.',
            'pdf' => 'Summarize this document for an assistant\'s memory in 1-3 sentences so it can '
                . 'recall the content later. Capture the title, purpose, and any key figures or names. '
                . 'Output only the summary.',
            'text' => 'Summarize this text/CSV file for an assistant\'s memory in 1-3 sentences so it '
                . 'can recall the content later. Capture what the data is, its columns/structure, and '
                . 'any notable values. Output only the summary.',
            default => 'Describe this image in ONE concise sentence for an assistant\'s memory, so it '
                . 'can recall the image later when the user refers back to it. Capture the key subject '
                . 'and any text, numbers, or food/product details visible. Output only the description.',
        };
    }

    private function detectMimeType(string $binary): string
    {
        $detected = new finfo(FILEINFO_MIME_TYPE)->buffer($binary);

        return is_string($detected) && $detected !== '' ? $detected : 'application/octet-stream';
    }

    private function normalize(string $description): string
    {
        $clean = trim(preg_replace('/\s+/', ' ', $description) ?? $description);

        if (mb_strlen($clean) > self::MAX_DESCRIPTION_LENGTH) {
            $clean = mb_substr($clean, 0, self::MAX_DESCRIPTION_LENGTH - 1) . '…';
        }

        return $clean;
    }
}
