<?php

declare(strict_types=1);

namespace Tests\Intelligence\Agents;

use Kanvas\Intelligence\Agents\Helpers\AgentChatBroadcastChannel;
use Kanvas\Intelligence\Agents\Models\Agent;
use Tests\TestCase;

final class AgentChatBroadcastChannelTest extends TestCase
{
    /** Pusher's allow-list — anything outside it throws `Invalid channel name` at broadcast time. */
    private const string PUSHER_SAFE = '/\A[-a-zA-Z0-9_=@,.;]+\z/';

    public function testNameForAnEmailSessionKeepsThePusherAllowList(): void
    {
        // An inbound Mailgun session id is the email channel slug, so a plus-addressed sender
        // (`ap+caf_=acme-dot-ap@example.com`) reaches this method with its `+` intact.
        $name = AgentChatBroadcastChannel::nameFor(
            $this->makeAgent(),
            'agent-1145-email-ap+caf_=acme-dot-ap-at-example-dot-com-31-9659'
        );

        $this->assertStringNotContainsString('+', $name);
        $this->assertMatchesRegularExpression(self::PUSHER_SAFE, $name);
    }

    public function testNameForKeysOnTheAgentTenantAndSession(): void
    {
        $name = AgentChatBroadcastChannel::nameFor($this->makeAgent(), 'wa-chat-18095551234-31-9659');

        $this->assertSame('agent-chat-31-9659-wa-chat-18095551234-31-9659', $name);
    }

    private function makeAgent(): Agent
    {
        $agent = new Agent();
        $agent->apps_id = 31;
        $agent->companies_id = 9659;

        return $agent;
    }
}
