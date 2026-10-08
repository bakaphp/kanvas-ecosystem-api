<?php

declare(strict_types=1);

namespace Tests\Intelligence\Agents\Chat;

use Kanvas\Intelligence\Agents\Actions\Chat\AgentChatKernel;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Users\Models\Users;
use Override;
use Tests\TestCase;

/**
 * The person waits for everything that runs before the broadcast. Persistence has to, because the
 * broadcast carries the Social message id that clients fetch large replies by; usage bookkeeping
 * does not, so it runs after the reply is out.
 */
final class AgentChatKernelDeliveryOrderTest extends TestCase
{
    public function testTheReplyIsBroadcastBeforeUsageIsRecordedAndAfterItIsPersisted(): void
    {
        $kernel = new class (
            agent: new Agent(['is_active' => true]),
            session: null,
            message: 'hello',
            user: new Users(),
        ) extends AgentChatKernel {
            /** @var list<string> */
            public array $order = [];

            #[Override]
            protected function runHandler(): string
            {
                return 'hi';
            }

            #[Override]
            protected function persistConversationToSocial(string $response): void
            {
                $this->order[] = 'persist';
            }

            #[Override]
            protected function broadcastChatResponse(string $sessionId, string $response): void
            {
                $this->order[] = 'broadcast';
            }

            #[Override]
            protected function trackUsage(
                string $response,
                float $durationMs,
                string $sessionId,
                int $threadWaitMs = 0,
            ): void {
                $this->order[] = 'usage';
            }
        };

        $this->assertSame('hi', $kernel->execute());
        $this->assertSame(['persist', 'broadcast', 'usage'], $kernel->order);
    }
}
