<?php

declare(strict_types=1);

namespace Tests\Intelligence\Agents;

use Kanvas\Intelligence\Agents\Neuron\KanvasMessageHistory;
use Kanvas\Intelligence\Agents\Neuron\SalesAssistKanvasMessageHistory;
use Kanvas\Intelligence\Agents\Services\AttachmentDescriptionService;
use Kanvas\Social\Messages\Models\Message as SocialMessage;
use ReflectionClass;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Guards the "agent remembers attachments" fix: both Neuron histories rebuild from text only, so an
 * attachment turn (image / audio / PDF) must be re-materialized as a "[Attachment: <desc>]" memory
 * line (and never dropped when it has no text). Pure marker logic — no DB or LLM — via reflection.
 */
class ImageMemoryMarkerTest extends TestCase
{
    private function salesMarker(array $stored, ?SocialMessage $message = null): string
    {
        $history = new ReflectionClass(SalesAssistKanvasMessageHistory::class)->newInstanceWithoutConstructor();

        return new ReflectionMethod(SalesAssistKanvasMessageHistory::class, 'buildAttachmentMarker')
            ->invoke($history, $stored, $message ?? new SocialMessage());
    }

    private function conversationMarker(?string $attachmentsJson): string
    {
        $history = new ReflectionClass(KanvasMessageHistory::class)->newInstanceWithoutConstructor();

        return new ReflectionMethod(KanvasMessageHistory::class, 'buildAttachmentMarker')
            ->invoke($history, $attachmentsJson);
    }

    public function testSalesDescriptionsBecomeMemoryLines(): void
    {
        $marker = $this->salesMarker([
            'content' => '',
            'attachment_descriptions' => ['a plate of grilled chicken and rice', 'transcript: see you at 5pm'],
        ]);

        $this->assertSame('[Attachment: a plate of grilled chicken and rice] [Attachment: transcript: see you at 5pm]', $marker);
    }

    public function testSalesFallsBackToAttachedMarkerWhenNoDescriptionYet(): void
    {
        $marker = $this->salesMarker([
            'content' => '',
            'images' => ['https://cdn.example/snap-1.jpg', 'https://cdn.example/snap-2.jpg'],
        ]);

        $this->assertSame('[Attachment] [Attachment]', trim($marker));
    }

    public function testSalesReturnsEmptyWhenNoAttachmentAndHasText(): void
    {
        $this->assertSame('', $this->salesMarker(['content' => 'just text, no attachment']));
    }

    public function testSalesIgnoresBlankDescriptions(): void
    {
        $marker = $this->salesMarker([
            'content' => 'hello',
            'attachment_descriptions' => ['', '   '],
        ]);

        $this->assertSame('', $marker);
    }

    public function testConversationDescriptionsBecomeMemoryLines(): void
    {
        $json = json_encode([
            ['url' => 'https://cdn.example/x.pdf', 'caption' => 'Invoice #42, total $1,200'],
        ]);

        $this->assertSame('[Attachment: Invoice #42, total $1,200]', $this->conversationMarker($json));
    }

    public function testConversationFallsBackWhenDescriptionMissing(): void
    {
        $json = json_encode([['url' => 'https://cdn.example/x.mp3', 'caption' => '']]);

        $this->assertSame('[Attachment]', $this->conversationMarker($json));
    }

    public function testConversationEmptyAttachmentsReturnEmpty(): void
    {
        $this->assertSame('', $this->conversationMarker(null));
        $this->assertSame('', $this->conversationMarker('[]'));
        $this->assertSame('', $this->conversationMarker(''));
    }

    public function testDescriptionLeadsWithTypeAndFilename(): void
    {
        $service = new ReflectionClass(AttachmentDescriptionService::class)->newInstanceWithoutConstructor();
        $label = new ReflectionMethod(AttachmentDescriptionService::class, 'label');

        $this->assertSame('PDF "receipt.pdf"', $label->invoke($service, 'application/pdf', 'receipt.pdf'));
        $this->assertSame('Image', $label->invoke($service, 'image/png', null));
        $this->assertSame('Image', $label->invoke($service, 'image/png', ''));
        $this->assertSame('Audio "memo.mp3"', $label->invoke($service, 'audio/mpeg', 'memo.mp3'));
    }

    public function testNativeKindClassifiesImageAudioPdf(): void
    {
        $this->assertSame('image', AttachmentDescriptionService::nativeKind('image/png'));
        $this->assertSame('audio', AttachmentDescriptionService::nativeKind('audio/mpeg'));
        $this->assertSame('pdf', AttachmentDescriptionService::nativeKind('application/pdf'));
        $this->assertNull(AttachmentDescriptionService::nativeKind('application/zip'));
        $this->assertNull(AttachmentDescriptionService::nativeKind('video/mp4'));
    }

    public function testDescriptionNormalizeCollapsesWhitespaceAndTruncates(): void
    {
        $service = new ReflectionClass(AttachmentDescriptionService::class)->newInstanceWithoutConstructor();
        $normalize = new ReflectionMethod(AttachmentDescriptionService::class, 'normalize');

        $this->assertSame('a clean single line', $normalize->invoke($service, "  a clean\n  single   line  "));

        $long = str_repeat('x', 800);
        $result = $normalize->invoke($service, $long);
        $this->assertLessThanOrEqual(600, mb_strlen($result));
        $this->assertStringEndsWith('…', $result);
    }
}
