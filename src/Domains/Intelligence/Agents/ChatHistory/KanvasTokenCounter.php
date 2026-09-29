<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\ChatHistory;

use NeuronAI\Chat\Enums\SourceType;
use NeuronAI\Chat\History\HistoryTrimmer;
use NeuronAI\Chat\History\TokenCounter;
use NeuronAI\Chat\Messages\ContentBlocks\ImageContent;
use Override;

/**
 * Neuron indexes `getimagesizefromstring()` unguarded, so inline bytes PHP cannot size (HEIC, AVIF,
 * a truncated upload) fail the turn (KANVAS-ECOSYSTEM-6HC), and sizes any non-inline image by
 * downloading it on every count the trimmer runs. Anything but a sizable inline image is estimated.
 */
class KanvasTokenCounter extends TokenCounter
{
    private const int ESTIMATED_IMAGE_SIDE = 1024;

    public static function trimmer(): HistoryTrimmer
    {
        return new HistoryTrimmer(new self());
    }

    #[Override]
    protected function handleImageBlock(ImageContent $block): int
    {
        $size = $this->inlineImageSize($block);

        return $this->calculateImageChars(
            $size[0] ?? self::ESTIMATED_IMAGE_SIDE,
            $size[1] ?? self::ESTIMATED_IMAGE_SIDE,
        );
    }

    /**
     * @return array{0: int, 1: int}|null
     */
    private function inlineImageSize(ImageContent $block): ?array
    {
        if ($block->sourceType !== SourceType::BASE64) {
            return null;
        }

        $data = base64_decode((string) preg_replace('/^data:[^,]*,/', '', $block->getContent()), true);
        $size = $data ? @getimagesizefromstring($data) : false;

        return $size && $size[0] > 0 && $size[1] > 0 ? [$size[0], $size[1]] : null;
    }
}
