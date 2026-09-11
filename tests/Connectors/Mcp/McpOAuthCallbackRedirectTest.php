<?php

declare(strict_types=1);

namespace Tests\Connectors\Mcp;

use Illuminate\Support\Facades\Redis;
use Illuminate\Testing\TestResponse;
use Kanvas\Connectors\Internal\Jobs\OAuthCallbackJob;
use Kanvas\Connectors\Mcp\Actions\CreateMcpOAuthReceiverAction;
use Kanvas\Workflow\Models\ReceiverWebhook;
use Kanvas\Workflow\Models\WorkflowAction;

/**
 * The browser comes back from the vendor's consent screen to the API's own domain. Without a redirect
 * the person is stranded on raw JSON there, and the UI never learns whether the connection happened —
 * so the callback sends them back to `redirect_url` with the outcome.
 */
final class McpOAuthCallbackRedirectTest extends McpTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        WorkflowAction::firstOrCreate(
            ['model_name' => OAuthCallbackJob::class],
            ['name' => 'OAuth Callback']
        );
    }

    public function testAFailedConnectionSendsTheBrowserBackWithTheReason(): void
    {
        $receiver = $this->receiver('https://app.example.test/agents/5?tab=tools');

        $response = $this->forgedCallback($receiver);

        $response->assertRedirect();
        $location = (string) $response->headers->get('Location');
        $this->assertStringStartsWith('https://app.example.test/agents/5?tab=tools&status=error&message=', $location);

        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);
        $this->assertStringContainsString('does not match a connection started here', $query['message']);
    }

    public function testTheResultIsPlacedBeforeTheFragmentSoAHashRouterStillSeesIt(): void
    {
        $receiver = $this->receiver('https://app.example.test/#/agents/5');

        $location = (string) $this->forgedCallback($receiver)->headers->get('Location');

        $this->assertStringStartsWith('https://app.example.test/?status=error&message=', $location);
        $this->assertStringEndsWith('#/agents/5', $location);
    }

    public function testWithoutARedirectUrlTheFailureIsStillAnswered(): void
    {
        $this->forgedCallback($this->receiver(null))
            ->assertStatus(500)
            ->assertJson(['error' => 'Authentication error']);
    }

    private function receiver(?string $redirectUrl): ReceiverWebhook
    {
        return new CreateMcpOAuthReceiverAction(
            agent: $this->makeAgent(),
            tool: $this->makeMcpTool($this->makeIntegration(['auth_methods' => ['oauth']])),
            user: $this->mcpUser,
            redirectUrl: $redirectUrl,
        )->execute();
    }

    /**
     * A callback whose `state` was never issued: the controller's own state entry exists, so the call
     * reaches the provider, which refuses it — the failure path without faking a whole vendor.
     */
    private function forgedCallback(ReceiverWebhook $receiver): TestResponse
    {
        Redis::setex('mcp_oauth:' . $receiver->uuid, 1800, json_encode([
            'nonce' => 'never-issued',
            'app_id' => $this->mcpApp->getId(),
        ]));

        return $this->get($receiver->getOAuthCallbackUrl() . '?state=forged&code=stolen');
    }
}
