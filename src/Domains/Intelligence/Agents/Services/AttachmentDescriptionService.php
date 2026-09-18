<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Services;

use Kanvas\Filesystem\Models\Filesystem;
use Kanvas\Filesystem\Services\FilesystemServices;
use Kanvas\Filesystem\Services\FileTextExtractor;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Neuron\Contracts\BehavesAsKanvasAgent;
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
        private readonly bool $allowStructuredText = true,
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

        if (! $handler instanceof BehavesAsKanvasAgent) {
            return null;
        }

        $handler->setConfiguration(agent: $agent, user: $user);

        return new self($handler->captionProvider(), ! $agent->conversesWithCustomer());
    }

    /**
     * The kind a MIME type rides as, or null when nothing here can carry it (video, archives) and its
     * URL stays in the prompt. Images, audio and PDF ride as binary blocks the model reads natively;
     * everything readable rides as text, DOCX and XLSX included — no content block carries those, so
     * FileTextExtractor turns them into text first.
     *
     * Which formats those are is FileTextExtractor's answer, not a second list here, so this path and
     * the read_file tool cover the same set.
     *
     * `$allowStructuredText` is false on a customer-facing surface: inlining puts bytes a stranger
     * chose straight into the prompt, so a prospect reaches only plain text and CSV. Their
     * JSON/XML/YAML/DOCX rides as a URL instead.
     */
    public static function nativeKind(string $mimeType, bool $allowStructuredText = true): ?string
    {
        $mimeType = self::normalizeMimeType($mimeType);

        return match (true) {
            str_starts_with($mimeType, 'image/') => 'image',
            str_starts_with($mimeType, 'audio/') => 'audio',
            // Ahead of the extractor on purpose: the model reads a PDF natively, better than our text.
            $mimeType === 'application/pdf' => 'pdf',
            self::isPlainText($mimeType) => 'text',
            $allowStructuredText && FileTextExtractor::extensionForMimeType($mimeType) !== null => 'text',
            default => null,
        };
    }

    /** finfo reports CSV/TSV as text/plain, so the text/* family plus the odd CSV label covers it. */
    private static function isPlainText(string $mimeType): bool
    {
        return str_starts_with($mimeType, 'text/')
            || in_array($mimeType, ['application/csv', 'application/x-csv'], true);
    }

    /**
     * A stored `file_type` can carry the charset finfo omits (`text/plain; charset=utf-8`), and
     * casing is not guaranteed either — both reach `nativeKind()`, so normalize before matching.
     */
    private static function normalizeMimeType(string $mimeType): string
    {
        return strtolower(trim(explode(';', $mimeType)[0]));
    }

    /**
     * Wrap raw attachment bytes in the matching Neuron content block, sniffing the MIME type when
     * the caller doesn't already know it. Null means the model can't take this type as a block —
     * its URL is already folded into the prompt text by AttachmentPromptBuilder upstream.
     */
    public static function contentBlockFor(
        string $binary,
        ?string $mimeType = null,
        bool $allowStructuredText = true,
    ): ?ContentBlockInterface {
        if ($binary === '') {
            return null;
        }

        // Normalized here rather than only inside nativeKind(): a stored file_type can carry a charset
        // and arbitrary casing, and it is handed straight to the provider as the block's media type.
        $mimeType = self::normalizeMimeType($mimeType ?? FilesystemServices::detectMimeTypeFromBytes($binary));
        $base64 = base64_encode($binary);

        return match (self::nativeKind($mimeType, $allowStructuredText)) {
            'image' => new ImageContent($base64, SourceType::BASE64, $mimeType),
            'audio' => new AudioContent($base64, SourceType::BASE64, $mimeType),
            'pdf' => new FileContent($base64, SourceType::BASE64, $mimeType),
            'text' => self::textBlock($binary, $mimeType),
            default => null,
        };
    }

    /**
     * Plain text goes in as-is; everything else is run through the extractor first, which is what
     * turns a DOCX or XLSX into something a model can read. Null when extraction yields nothing —
     * a scan, an empty export — so the caller leaves the URL in the prompt rather than an empty block.
     */
    private static function textBlock(string $binary, string $mimeType): ?TextContent
    {
        $text = $binary;

        if (! self::isPlainText($mimeType)) {
            $extension = FileTextExtractor::extensionForMimeType($mimeType);
            $text = $extension === null ? '' : new FileTextExtractor()->extractFrom($binary, $extension);
        }

        return $text === '' ? null : new TextContent(self::wrapTextForBlock($text, $mimeType));
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
        $binary = AttachmentFetchService::fetch($url);

        if ($binary === null || $binary === '') {
            return '';
        }

        try {
            $mimeType = FilesystemServices::detectMimeTypeFromBytes($binary);
            $block = self::contentBlockFor($binary, $mimeType, allowStructuredText: $this->allowStructuredText);

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

    private function normalize(string $description): string
    {
        $clean = trim(preg_replace('/\s+/', ' ', $description) ?? $description);

        if (mb_strlen($clean) > self::MAX_DESCRIPTION_LENGTH) {
            $clean = mb_substr($clean, 0, self::MAX_DESCRIPTION_LENGTH - 1) . '…';
        }

        return $clean;
    }
}
