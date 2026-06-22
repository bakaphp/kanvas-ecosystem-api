<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Services;

use Baka\Http\SafeUrlFetcher;
use finfo;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Neuron\BaseKanvasAgent;
use Kanvas\Users\Models\Users;
use NeuronAI\Chat\Enums\SourceType;
use NeuronAI\Chat\Messages\ContentBlocks\ImageContent;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Providers\AIProviderInterface;
use Throwable;

/**
 * Turns an image into one short text caption using the SAME provider/model the agent runs on,
 * so the agent's text-only chat history can "remember" what an image was on later turns (the live
 * turn sees the real bytes via ImageContent; rebuilt history only carries text). One raw
 * provider->chat() call — no tools, no agent system prompt — keeps it cheap and side-effect free.
 */
class ImageCaptionService
{
    private const int MAX_CAPTION_LENGTH = 280;

    private const string CAPTION_PROMPT =
        'Describe this image in ONE concise sentence for an assistant\'s memory, so it can recall '
        . 'the image later when the user refers back to it. Capture the key subject and any text, '
        . 'numbers, or food/product details visible. Output only the description — no preamble, no quotes.';

    public function __construct(
        private readonly AIProviderInterface $provider,
    ) {
    }

    /**
     * Build the captioner from an Agent by reusing its configured Neuron provider. Returns null
     * when the agent isn't Neuron-backed (runtime/ADK agents caption nothing here).
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
     * Caption each URL, preserving order. A failed fetch/caption yields '' for that slot so the
     * result stays index-aligned with the input — callers decide whether to keep the empties.
     *
     * @param list<string> $imageUrls
     * @return list<string>
     */
    public function captionUrls(array $imageUrls): array
    {
        return array_map(fn (string $url): string => $this->captionUrl($url), array_values($imageUrls));
    }

    public function captionUrl(string $url): string
    {
        try {
            $binary = SafeUrlFetcher::fetch($url);

            if ($binary === '') {
                return '';
            }

            $message = new UserMessage(self::CAPTION_PROMPT);
            $message->addContent(
                new ImageContent(
                    content: base64_encode($binary),
                    sourceType: SourceType::BASE64,
                    mediaType: $this->detectMediaType($binary),
                )
            );

            $response = $this->provider->chat($message);

            return $this->normalize((string) ($response->getContent() ?? ''));
        } catch (Throwable $e) {
            report($e);

            return '';
        }
    }

    private function detectMediaType(string $binary): string
    {
        $detected = new finfo(FILEINFO_MIME_TYPE)->buffer($binary);

        return is_string($detected) && str_starts_with($detected, 'image/')
            ? $detected
            : 'image/png';
    }

    private function normalize(string $caption): string
    {
        $clean = trim(preg_replace('/\s+/', ' ', $caption) ?? $caption);

        if (mb_strlen($clean) > self::MAX_CAPTION_LENGTH) {
            $clean = mb_substr($clean, 0, self::MAX_CAPTION_LENGTH - 1) . '…';
        }

        return $clean;
    }
}
