<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Services;

use Baka\Http\SafeUrlFetcher;
use finfo;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Neuron\BaseKanvasAgent;
use Kanvas\Users\Models\Users;
use NeuronAI\Chat\Enums\SourceType;
use NeuronAI\Chat\Messages\ContentBlocks\AudioContent;
use NeuronAI\Chat\Messages\ContentBlocks\ContentBlockInterface;
use NeuronAI\Chat\Messages\ContentBlocks\FileContent;
use NeuronAI\Chat\Messages\ContentBlocks\ImageContent;
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
     * content block (docx/xlsx, video, etc. — those stay URL-in-prompt). Images, audio and PDF are
     * the formats Gemini accepts inline.
     */
    public static function nativeKind(string $mimeType): ?string
    {
        return match (true) {
            str_starts_with($mimeType, 'image/') => 'image',
            str_starts_with($mimeType, 'audio/') => 'audio',
            $mimeType === 'application/pdf' => 'pdf',
            default => null,
        };
    }

    /**
     * Describe each URL, preserving order. A failed fetch/describe yields '' for that slot so the
     * result stays index-aligned with the input — callers decide whether to keep the empties.
     *
     * @param list<string> $urls
     * @return list<string>
     */
    public function describeUrls(array $urls): array
    {
        return array_map(fn (string $url): string => $this->describeUrl($url), array_values($urls));
    }

    public function describeUrl(string $url): string
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

            return $this->normalize((string) ($response->getContent() ?? ''));
        } catch (Throwable $e) {
            report($e);

            return '';
        }
    }

    private function buildContentBlock(string $binary, string $mimeType): ?ContentBlockInterface
    {
        $base64 = base64_encode($binary);

        return match (self::nativeKind($mimeType)) {
            'image' => new ImageContent($base64, SourceType::BASE64, $mimeType),
            'audio' => new AudioContent($base64, SourceType::BASE64, $mimeType),
            'pdf' => new FileContent($base64, SourceType::BASE64, $mimeType),
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
