<?php

declare(strict_types=1);

namespace Tests\Intelligence\Agents;

use Kanvas\Intelligence\Agents\Neuron\KanvasMessageHistory;
use Kanvas\Intelligence\Agents\Neuron\SalesAssistKanvasMessageHistory;
use Kanvas\Intelligence\Agents\Services\ImageCaptionService;
use Kanvas\Social\Messages\Models\Message as SocialMessage;
use ReflectionClass;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Guards the "agent remembers images" fix: both Neuron histories rebuild from text only, so an
 * image turn must be re-materialized as a "[Image: <caption>]" memory line (and never dropped when
 * it has no text). Pure marker logic — no DB or LLM — exercised via reflection.
 */
class ImageMemoryMarkerTest extends TestCase
{
    private function salesMarker(array $stored, ?SocialMessage $message = null): string
    {
        $history = new ReflectionClass(SalesAssistKanvasMessageHistory::class)->newInstanceWithoutConstructor();

        return new ReflectionMethod(SalesAssistKanvasMessageHistory::class, 'buildImageMarker')
            ->invoke($history, $stored, $message ?? new SocialMessage());
    }

    private function conversationMarker(?string $attachmentsJson): string
    {
        $history = new ReflectionClass(KanvasMessageHistory::class)->newInstanceWithoutConstructor();

        return new ReflectionMethod(KanvasMessageHistory::class, 'buildImageMarker')
            ->invoke($history, $attachmentsJson);
    }

    public function testSalesCaptionsBecomeMemoryLines(): void
    {
        $marker = $this->salesMarker([
            'content' => '',
            'image_descriptions' => ['a plate of grilled chicken and rice', 'a glass of water'],
        ]);

        $this->assertSame('[Image: a plate of grilled chicken and rice] [Image: a glass of water]', $marker);
    }

    public function testSalesFallsBackToAttachedMarkerWhenNoCaptionYet(): void
    {
        $marker = $this->salesMarker([
            'content' => '',
            'images' => ['https://cdn.example/snap-1.jpg', 'https://cdn.example/snap-2.jpg'],
        ]);

        $this->assertSame('[Image attached] [Image attached]', trim($marker));
    }

    public function testSalesReturnsEmptyWhenNoImageAndHasText(): void
    {
        $this->assertSame('', $this->salesMarker(['content' => 'just text, no image']));
    }

    public function testSalesIgnoresBlankCaptions(): void
    {
        $marker = $this->salesMarker([
            'content' => 'hello',
            'image_descriptions' => ['', '   '],
        ]);

        $this->assertSame('', $marker);
    }

    public function testConversationCaptionsBecomeMemoryLines(): void
    {
        $json = json_encode([
            ['url' => 'https://cdn.example/x.jpg', 'caption' => 'a sunset over the ocean'],
        ]);

        $this->assertSame('[Image: a sunset over the ocean]', $this->conversationMarker($json));
    }

    public function testConversationFallsBackWhenCaptionMissing(): void
    {
        $json = json_encode([['url' => 'https://cdn.example/x.jpg', 'caption' => '']]);

        $this->assertSame('[Image attached]', $this->conversationMarker($json));
    }

    public function testConversationEmptyAttachmentsReturnEmpty(): void
    {
        $this->assertSame('', $this->conversationMarker(null));
        $this->assertSame('', $this->conversationMarker('[]'));
        $this->assertSame('', $this->conversationMarker(''));
    }

    public function testCaptionNormalizeCollapsesWhitespaceAndTruncates(): void
    {
        $service = new ReflectionClass(ImageCaptionService::class)->newInstanceWithoutConstructor();
        $normalize = new ReflectionMethod(ImageCaptionService::class, 'normalize');

        $this->assertSame('a clean single line', $normalize->invoke($service, "  a clean\n  single   line  "));

        $long = str_repeat('x', 400);
        $result = $normalize->invoke($service, $long);
        $this->assertLessThanOrEqual(280, mb_strlen($result));
        $this->assertStringEndsWith('…', $result);
    }
}
