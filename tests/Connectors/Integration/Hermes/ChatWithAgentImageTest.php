<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Hermes;

use Kanvas\Connectors\Hermes\Actions\ChatWithAgentAction;
use Kanvas\Intelligence\Agents\Models\Agent;
use Override;
use Tests\TestCase;

/**
 * Hermes' vision pipeline only reliably accepts images inlined as base64 `data:` URIs — a
 * remote http(s) URL leaves the agent unable to see the image. These tests pin the payload
 * shape `buildContent()` produces.
 */
class ChatWithAgentImageTest extends TestCase
{
    public function testImagesAreInlinedAsBase64DataUris(): void
    {
        // A real 1x1 PNG so finfo resolves the media type to image/png.
        $png = (string) base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAAC0lEQVR42mNgAAIAAAUAAen63NgAAAAASUVORK5CYII=',
            true,
        );

        $action = new InlineImageChatWithAgentActionStub(
            new Agent(),
            'what is in this image?',
            ['https://cdn.example.com/photo.png'],
            $png,
        );

        $content = $action->exposeBuildContent();

        $this->assertIsArray($content);
        $this->assertSame(['type' => 'text', 'text' => 'what is in this image?'], $content[0]);
        $this->assertSame('image_url', $content[1]['type']);
        $this->assertStringStartsWith('data:image/png;base64,', $content[1]['image_url']['url']);
        $this->assertStringContainsString(base64_encode($png), $content[1]['image_url']['url']);
    }

    public function testDataUriImagesArePassedThroughUnchanged(): void
    {
        $dataUri = 'data:image/jpeg;base64,QUJD';

        $action = new InlineImageChatWithAgentActionStub(new Agent(), 'hi', [$dataUri], 'unused');

        $content = $action->exposeBuildContent();

        $this->assertIsArray($content);
        $this->assertSame($dataUri, $content[1]['image_url']['url']);
    }

    public function testTextOnlyMessagesStayPlainStrings(): void
    {
        $action = new InlineImageChatWithAgentActionStub(new Agent(), 'just text', [], 'unused');

        $this->assertSame('just text', $action->exposeBuildContent());
    }
}

class InlineImageChatWithAgentActionStub extends ChatWithAgentAction
{
    /**
     * @param list<string> $images
     */
    public function __construct(
        Agent $agent,
        string $message,
        array $images,
        private readonly string $cannedBinary,
    ) {
        parent::__construct($agent, $message, $images);
    }

    #[Override]
    protected function fetchImageBinary(string $url): string
    {
        return $this->cannedBinary;
    }

    public function exposeBuildContent(): string|array
    {
        return $this->buildContent();
    }
}
