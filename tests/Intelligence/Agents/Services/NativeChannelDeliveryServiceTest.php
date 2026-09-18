<?php

declare(strict_types=1);

namespace Tests\Intelligence\Agents\Services;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Intelligence\AgentRuntime\Enums\AgentChannelTokenEnum;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Services\NativeChannelDeliveryService;
use Kanvas\Social\Channels\Models\Channel;
use Kanvas\Users\Models\Users;
use Tests\TestCase;

final class NativeChannelDeliveryServiceTest extends TestCase
{
    private Apps $kanvasApp;
    private Companies $company;
    private Users $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->kanvasApp = app(Apps::class);
        $this->user = auth()->user();
        $this->company = $this->user->getCurrentCompany();
    }

    public function testSlackPushIsSentAsNativeMarkdownIntoTheThread(): void
    {
        Http::fake([
            'slack.com/api/chat.postMessage' => Http::response(['ok' => true, 'ts' => '1700000000.000200']),
        ]);

        $delivered = NativeChannelDeliveryService::deliver(
            $this->slackChannel(),
            "### Part 1: Board Updates\n\n**Stage Movement:** Advanced to **In Negotiation**.",
            $this->connectedAgent(),
            'slack:T123:C456:1700000000.000100',
        );

        $this->assertTrue($delivered);
        Http::assertSent(
            fn (Request $request): bool => str_contains($request->url(), 'chat.postMessage')
                && $request['channel'] === 'C456'
                && $request['thread_ts'] === '1700000000.000100'
                && $request['markdown_text'] === "### Part 1: Board Updates\n\n**Stage Movement:** Advanced to **In Negotiation**."
                && ! array_key_exists('text', $request->data())
        );
    }

    private function connectedAgent(): Agent
    {
        $agent = Agent::factory()
            ->withAppId($this->kanvasApp->getId())
            ->withCompanyId($this->company->getId())
            ->create(['name' => 'Jessica', 'user_id' => $this->user->getId()]);

        $agent->set(AgentChannelTokenEnum::SLACK_BOT_TOKEN->value, 'xoxb-real-token');

        return $agent;
    }

    private function slackChannel(): Channel
    {
        $slug = 'slack-' . fake()->unique()->uuid();

        return Channel::create([
            'name' => $slug,
            'slug' => $slug,
            'description' => 'Slack channel used in tests',
            'apps_id' => $this->kanvasApp->getId(),
            'companies_id' => $this->company->getId(),
            'users_id' => $this->user->getId(),
        ]);
    }
}
