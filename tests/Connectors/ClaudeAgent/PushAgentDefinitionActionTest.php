<?php

declare(strict_types=1);

namespace Tests\Connectors\ClaudeAgent;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\ClaudeAgent\Actions\PushAgentDefinitionAction;
use Kanvas\Connectors\ClaudeAgent\Client;
use Kanvas\Connectors\ClaudeAgent\Enums\CustomFieldEnum;
use Kanvas\Intelligence\Agents\Models\Agent;
use Tests\Connectors\Traits\HasClaudeAgentConfiguration;
use Tests\TestCase;

final class PushAgentDefinitionActionTest extends TestCase
{
    use DatabaseTransactions;
    use HasClaudeAgentConfiguration;

    /** Settings live on mysql; agents, types and sessions on intelligence. */
    protected array $connectionsToTransact = ['mysql', 'intelligence'];

    private Apps $currentApp;
    private Companies $currentCompany;

    protected function setUp(): void
    {
        parent::setUp();

        $this->currentApp = app(Apps::class);
        $this->currentCompany = static::$cachedUser->getCurrentCompany();
        $this->configureClaudeAgent($this->currentApp, $this->currentCompany);
    }

    private function makeAgent(string $soul = 'You are a hosted teammate.'): Agent
    {
        return $this->makeClaudeAgent($this->currentApp, $this->currentCompany, ['soul' => $soul]);
    }

    public function testFirstPushCreatesTheRemoteAgentAndStoresItsLinkage(): void
    {
        $agent = $this->makeAgent();

        $client = $this->claudeAgentClientReturning($this->currentApp, $this->currentCompany, [
            $this->claudeAgentJsonResponse(200, ['id' => 'agent_01abc', 'version' => 1]),
        ]);

        $result = new PushAgentDefinitionAction($agent, $client)->execute();

        $this->assertTrue($result['pushed']);
        $this->assertSame('agent_01abc', $result['id']);
        $this->assertSame(1, $result['version']);

        $this->assertSame('agent_01abc', $agent->get(CustomFieldEnum::CLAUDE_AGENT_ID->value));
        $this->assertSame('1', (string) $agent->get(CustomFieldEnum::CLAUDE_AGENT_VERSION->value));
        $this->assertNotEmpty($agent->get(CustomFieldEnum::CLAUDE_AGENT_FINGERPRINT->value));
    }

    /**
     * The load-bearing one. This runs on the chat path, so an unchanged spec must cost zero HTTP
     * calls — pushing per turn would mint a remote version per message. The client is built with an
     * empty mock queue, so any request at all throws instead of silently passing.
     */
    public function testUnchangedSpecMakesNoHttpCall(): void
    {
        $agent = $this->makeAgent();

        $seed = $this->claudeAgentClientReturning($this->currentApp, $this->currentCompany, [
            $this->claudeAgentJsonResponse(200, ['id' => 'agent_01abc', 'version' => 4]),
        ]);
        new PushAgentDefinitionAction($agent, $seed)->execute();

        $noCallsAllowed = new Client(
            $this->currentApp,
            $this->currentCompany,
            $this->claudeAgentGuzzleReturning([]),
        );

        $result = new PushAgentDefinitionAction($agent->refresh(), $noCallsAllowed)->execute();

        $this->assertFalse($result['pushed']);
        $this->assertSame('agent_01abc', $result['id']);
        $this->assertSame(4, $result['version']);
    }

    /**
     * A changed soul must version the existing agent, never create a second one — the remote agent
     * is a persistent resource and re-creating it orphans the old object and its history.
     */
    public function testChangedSpecUpdatesTheExistingAgentAndBumpsTheVersion(): void
    {
        $agent = $this->makeAgent();

        $seed = $this->claudeAgentClientReturning($this->currentApp, $this->currentCompany, [
            $this->claudeAgentJsonResponse(200, ['id' => 'agent_01abc', 'version' => 1]),
        ]);
        new PushAgentDefinitionAction($agent, $seed)->execute();
        $firstFingerprint = (string) $agent->get(CustomFieldEnum::CLAUDE_AGENT_FINGERPRINT->value);

        $agent->soul = 'You are a terse hosted teammate.';
        $agent->saveQuietly();

        $update = $this->claudeAgentClientReturning($this->currentApp, $this->currentCompany, [
            $this->claudeAgentJsonResponse(200, ['id' => 'agent_01abc', 'version' => 2]),
        ]);

        $result = new PushAgentDefinitionAction($agent->refresh(), $update)->execute();

        $this->assertTrue($result['pushed']);
        $this->assertSame('agent_01abc', $result['id']);
        $this->assertSame(2, $result['version']);
        $this->assertNotSame(
            $firstFingerprint,
            (string) $agent->get(CustomFieldEnum::CLAUDE_AGENT_FINGERPRINT->value),
        );
    }

    public function testAResponseWithoutAnIdIsRejected(): void
    {
        $agent = $this->makeAgent();

        $client = $this->claudeAgentClientReturning($this->currentApp, $this->currentCompany, [
            $this->claudeAgentJsonResponse(200, ['version' => 1]),
        ]);

        $this->expectExceptionMessage('agent without an id');

        new PushAgentDefinitionAction($agent, $client)->execute();
    }
}
