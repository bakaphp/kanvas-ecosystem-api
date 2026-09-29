<?php

declare(strict_types=1);

namespace Tests\Intelligence\Agents;

use Kanvas\Intelligence\Agents\ChatHistory\KanvasTokenCounter;
use NeuronAI\Chat\Enums\SourceType;
use NeuronAI\Chat\Messages\ContentBlocks\ImageContent;
use NeuronAI\Chat\Messages\ContentBlocks\TextContent;
use NeuronAI\Chat\Messages\UserMessage;
use Tests\TestCase;

/**
 * Regression for KANVAS-ECOSYSTEM-6HC: an inline image PHP cannot size made Neuron's TokenCounter
 * index `false`, which failed the turn while the history was being trimmed.
 */
final class KanvasTokenCounterTest extends TestCase
{
    private function imageMessage(string $content, SourceType $sourceType): UserMessage
    {
        return new UserMessage([
            new TextContent('what is this?'),
            new ImageContent($content, $sourceType, 'image/heic'),
        ]);
    }

    public function testUnsizableInlineImageIsEstimatedInsteadOfCrashing(): void
    {
        $message = $this->imageMessage(base64_encode('ftypheic not a size-able image'), SourceType::BASE64);

        $this->assertGreaterThan(0, new KanvasTokenCounter()->count($message));
    }

    public function testRealInlineImageIsStillSized(): void
    {
        $image = imagecreatetruecolor(100, 100);
        ob_start();
        imagepng($image);
        $png = 'data:image/png;base64,' . base64_encode((string) ob_get_clean());

        $small = new KanvasTokenCounter()->count($this->imageMessage($png, SourceType::BASE64));
        $unsizable = new KanvasTokenCounter()->count($this->imageMessage(base64_encode('junk'), SourceType::BASE64));

        $this->assertLessThan($unsizable, $small);
    }

    public function testUrlImageIsEstimatedWithoutFetching(): void
    {
        $message = $this->imageMessage('http://10.255.255.1/photo.jpg', SourceType::URL);

        $start = microtime(true);
        $tokens = new KanvasTokenCounter()->count($message);

        $this->assertGreaterThan(0, $tokens);
        $this->assertLessThan(1.0, microtime(true) - $start);
    }

    public function testTrimmerUsesTheGuardedCounter(): void
    {
        $trimmer = KanvasTokenCounter::trimmer();
        $messages = [$this->imageMessage(base64_encode('junk'), SourceType::BASE64)];

        $this->assertCount(1, $trimmer->trim($messages, 50_000));
    }
}
