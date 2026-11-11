<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\AgentRuntime\Harness\Actions;

use Kanvas\Intelligence\AgentRuntime\Harness\Concerns\PostsSessionActivity;
use Kanvas\Intelligence\AgentRuntime\Harness\Models\AgentTaskSession;

class PostCodingChatMessageAction
{
    use PostsSessionActivity;

    public function __construct(
        private readonly AgentTaskSession $session,
        private readonly string $content,
    ) {
    }

    public function execute(): void
    {
        $this->postToChat($this->session, $this->content);
    }
}
