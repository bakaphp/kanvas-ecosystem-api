<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Hermes;

use Kanvas\Connectors\Hermes\Actions\ChatWithAgentAction;
use Kanvas\Intelligence\Agents\Models\Agent;
use Override;
use Tests\TestCase;

/**
 * Pins the /v1/responses payload Hermes produces: images inlined as base64 `data:` URIs (its
 * vision pipeline can't fetch remote URLs) and the Kanvas Session.uuid threaded as the
 * `conversation` id so the gateway's stateful store keeps cross-turn context.
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

        $input = $action->exposeBuildInput();

        $this->assertIsArray($input);
        $message = $input[0];
        $this->assertSame('message', $message['type']);
        $this->assertSame('user', $message['role']);

        $content = $message['content'];
        $this->assertSame(['type' => 'input_text', 'text' => 'what is in this image?'], $content[0]);
        $this->assertSame('input_image', $content[1]['type']);
        $this->assertStringStartsWith('data:image/png;base64,', $content[1]['image_url']);
        $this->assertStringContainsString(base64_encode($png), $content[1]['image_url']);
    }

    public function testDataUriImagesArePassedThroughUnchanged(): void
    {
        $dataUri = 'data:image/jpeg;base64,QUJD';

        $action = new InlineImageChatWithAgentActionStub(new Agent(), 'hi', [$dataUri], 'unused');

        $input = $action->exposeBuildInput();

        $this->assertIsArray($input);
        $this->assertSame($dataUri, $input[0]['content'][1]['image_url']);
    }

    public function testTextOnlyMessagesStayPlainStrings(): void
    {
        $action = new InlineImageChatWithAgentActionStub(new Agent(), 'just text', [], 'unused');

        $this->assertSame('just text', $action->exposeBuildInput());
    }

    public function testPayloadThreadsSessionAsConversation(): void
    {
        $action = new InlineImageChatWithAgentActionStub(new Agent(), 'hi', [], 'unused', 'sess-uuid-123');

        $payload = json_decode($action->exposeBuildPayload(), true);

        $this->assertSame('hermes-agent', $payload['model']);
        $this->assertSame('hi', $payload['input']);
        $this->assertSame('sess-uuid-123', $payload['conversation']);
        $this->assertTrue($payload['store']);
    }

    public function testPayloadOmitsConversationWithoutSession(): void
    {
        $action = new InlineImageChatWithAgentActionStub(new Agent(), 'hi', [], 'unused');

        $payload = json_decode($action->exposeBuildPayload(), true);

        $this->assertArrayNotHasKey('conversation', $payload);
    }
}

/**
 * Supplies canned image bytes so the data-URI conversion is exercised without network, and
 * exposes the otherwise-protected payload builders.
 */
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
        ?string $sessionKey = null,
    ) {
        parent::__construct($agent, $message, $sessionKey, $images);
    }

    #[Override]
    protected function fetchImageBinary(string $url): string
    {
        return $this->cannedBinary;
    }

    public function exposeBuildInput(): string|array
    {
        return $this->buildInput();
    }

    public function exposeBuildPayload(): string
    {
        return $this->buildPayload();
    }
}
