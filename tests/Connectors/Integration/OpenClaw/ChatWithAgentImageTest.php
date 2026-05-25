<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\OpenClaw;

use Kanvas\Connectors\OpenClaw\Actions\ChatWithAgentAction;
use Kanvas\Intelligence\Agents\Models\Agent;
use Tests\TestCase;

/**
 * OpenClaw's `/v1/responses` documents `input_image` with a `source` object — the flat
 * `image_url` shape is the old form no longer recognized after the gateway's input_image
 * fix. These tests pin the documented payload shape `buildInput()` emits.
 *
 * Reference: https://docs.openclaw.ai/gateway/openresponses-http-api#images-input_image
 */
class ChatWithAgentImageTest extends TestCase
{
    public function testImagesAreSentUnderASourceUrlObject(): void
    {
        $action = new BuildInputChatWithAgentActionStub(
            new Agent(),
            'what is in this image?',
            null,
            ['https://cdn.example.com/photo.png'],
        );

        $input = $action->exposeBuildInput();

        $this->assertIsArray($input);
        $this->assertSame('message', $input[0]['type']);
        $this->assertSame('user', $input[0]['role']);
        $this->assertSame(
            ['type' => 'input_text', 'text' => 'what is in this image?'],
            $input[0]['content'][0],
        );
        $this->assertSame(
            [
                'type' => 'input_image',
                'source' => ['type' => 'url', 'url' => 'https://cdn.example.com/photo.png'],
            ],
            $input[0]['content'][1],
        );
    }

    public function testMultipleImagesEachGetTheirOwnSourceBlock(): void
    {
        $action = new BuildInputChatWithAgentActionStub(
            new Agent(),
            'compare these',
            null,
            ['https://cdn.example.com/a.jpg', 'https://cdn.example.com/b.jpg'],
        );

        $input = $action->exposeBuildInput();

        $this->assertCount(3, $input[0]['content']);
        $this->assertSame('input_image', $input[0]['content'][1]['type']);
        $this->assertSame('https://cdn.example.com/a.jpg', $input[0]['content'][1]['source']['url']);
        $this->assertSame('input_image', $input[0]['content'][2]['type']);
        $this->assertSame('https://cdn.example.com/b.jpg', $input[0]['content'][2]['source']['url']);
    }

    public function testTextOnlyMessagesStayPlainStrings(): void
    {
        $action = new BuildInputChatWithAgentActionStub(new Agent(), 'just text', null, []);

        $this->assertSame('just text', $action->exposeBuildInput());
    }
}

/**
 * Exposes the otherwise-protected payload builder.
 */
class BuildInputChatWithAgentActionStub extends ChatWithAgentAction
{
    public function exposeBuildInput(): string|array
    {
        return $this->buildInput();
    }
}
