<?php

declare(strict_types=1);

namespace Tests\Connectors\ClaudeAgent;

use Baka\Support\Str;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\ClaudeAgent\Actions\OpenSessionAction;
use Kanvas\Connectors\ClaudeAgent\Actions\RunSessionTurnAction;
use Kanvas\Connectors\ClaudeAgent\Enums\ConfigurationEnum;
use Kanvas\Connectors\ClaudeAgent\Exceptions\ClaudeAgentApiException;
use Kanvas\Connectors\ClaudeAgent\Services\CustomToolBridgeService;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Sessions\Models\Session;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use Tests\Connectors\Traits\HasClaudeAgentConfiguration;
use Tests\TestCase;

/**
 * End-to-end over the wire shape: message in, tool call back to Kanvas, result returned, answer out.
 * This is the integration the whole connector exists for, so it is exercised against the real
 * request-building path with canned HTTP rather than stubs.
 */
final class RunSessionTurnActionTest extends TestCase
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
        // Pre-cached so the turn doesn't spend a request provisioning one.
        $this->currentCompany->set(ConfigurationEnum::ENVIRONMENT_ID->value, 'env_cached');
    }

    private function makeAgent(): Agent
    {
        return $this->makeClaudeAgent($this->currentApp, $this->currentCompany);
    }

    private function makeSession(Agent $agent): Session
    {
        return Session::create([
            'apps_id' => $this->currentApp->getId(),
            'companies_id' => $this->currentCompany->getId(),
            'agents_id' => $agent->getId(),
            'uuid' => Str::uuid()->toString(),
            'canal_id' => '',
            'entity_namespace' => '',
            'entity_id' => 0,
            'user' => [],
            'content' => [],
        ]);
    }

    private function leadTool(): Tool
    {
        return Tool::make('get_lead_status', 'Look up a lead status by id.')
            ->addProperty(new ToolProperty(
                name: 'lead_id',
                type: PropertyType::INTEGER,
                description: 'Kanvas lead id.',
                required: true,
            ))
            ->setCallable(static fn (int $lead_id): array => ['lead_id' => $lead_id, 'status' => 'won']);
    }

    private function runTurn(Agent $agent, ?Session $session, array $responses, array $tools = []): string
    {
        return new RunSessionTurnAction(
            agent: $agent,
            session: $session,
            message: 'What is lead 42 doing?',
            client: $this->claudeAgentClientReturning(
                $this->currentApp,
                $this->currentCompany,
                $responses,
            ),
            deadlineMs: 2_000,
            pollIntervalMs: 0,
            bridge: new CustomToolBridgeService($agent, $tools),
        )->execute();
    }

    public function testAPlainTurnReturnsTheAgentsAnswer(): void
    {
        $agent = $this->makeAgent();
        $session = $this->makeSession($agent);

        $answer = $this->runTurn($agent, $session, [
            $this->claudeAgentJsonResponse(200, ['id' => 'agent_01', 'version' => 1]),
            $this->claudeAgentJsonResponse(200, ['id' => 'sesn_01']),
            $this->claudeAgentJsonResponse(200, []),
            $this->claudeAgentJsonResponse(200, [
                'data' => [
                    [
                        'id' => 'sevt_1',
                        'type' => 'agent.message',
                        'content' => [['type' => 'text', 'text' => 'Lead 42 is won.']],
                    ],
                    ['id' => 'sevt_2', 'type' => 'session.status_idle', 'stop_reason' => ['type' => 'end_turn']],
                ],
                'next_page' => null,
            ]),
        ]);

        $this->assertSame('Lead 42 is won.', $answer);
        $this->assertSame('sesn_01', OpenSessionAction::storedSessionId($session->refresh()));
        $this->assertSame('sevt_2', OpenSessionAction::storedCursor($session));
    }

    /**
     * The round-trip that defines this connector: the hosted agent calls a Kanvas tool, we execute
     * it in-process, hand the result back, and the agent finishes its answer with it.
     */
    public function testAToolCallIsServedLocallyAndTheTurnContinues(): void
    {
        $agent = $this->makeAgent();
        $session = $this->makeSession($agent);

        $answer = $this->runTurn($agent, $session, [
            $this->claudeAgentJsonResponse(200, ['id' => 'agent_01', 'version' => 1]),
            $this->claudeAgentJsonResponse(200, ['id' => 'sesn_01']),
            $this->claudeAgentJsonResponse(200, []),
            // First drain: the agent asks Kanvas for the lead and the session blocks on us.
            $this->claudeAgentJsonResponse(200, [
                'data' => [
                    [
                        'id' => 'sevt_1',
                        'type' => 'agent.custom_tool_use',
                        'name' => 'get_lead_status',
                        'input' => ['lead_id' => 42],
                    ],
                    [
                        'id' => 'sevt_2',
                        'type' => 'session.status_idle',
                        'stop_reason' => ['type' => 'requires_action', 'event_ids' => ['sevt_1']],
                    ],
                ],
                'next_page' => null,
            ]),
            // Our user.custom_tool_result POST.
            $this->claudeAgentJsonResponse(200, []),
            // Second drain: the agent answers using what we returned.
            $this->claudeAgentJsonResponse(200, [
                'data' => [
                    [
                        'id' => 'sevt_1',
                        'type' => 'agent.custom_tool_use',
                        'name' => 'get_lead_status',
                        'input' => ['lead_id' => 42],
                    ],
                    [
                        'id' => 'sevt_2',
                        'type' => 'session.status_idle',
                        'stop_reason' => ['type' => 'requires_action', 'event_ids' => ['sevt_1']],
                    ],
                    [
                        'id' => 'sevt_3',
                        'type' => 'agent.message',
                        'content' => [['type' => 'text', 'text' => 'Lead 42 is won.']],
                    ],
                    ['id' => 'sevt_4', 'type' => 'session.status_idle', 'stop_reason' => ['type' => 'end_turn']],
                ],
                'next_page' => null,
            ]),
        ], [$this->leadTool()]);

        $this->assertSame('Lead 42 is won.', $answer);
        $this->assertSame('sevt_4', OpenSessionAction::storedCursor($session->refresh()));
    }

    /**
     * Blocked on something with no matching tool call. Spinning to the deadline would report a
     * timeout and hide the real cause, so this must fail with the actual reason.
     */
    public function testBlockedOnAnUnservableActionFailsLoudly(): void
    {
        $agent = $this->makeAgent();
        $session = $this->makeSession($agent);

        $this->expectException(ClaudeAgentApiException::class);
        $this->expectExceptionMessage('not supported');

        $this->runTurn($agent, $session, [
            $this->claudeAgentJsonResponse(200, ['id' => 'agent_01', 'version' => 1]),
            $this->claudeAgentJsonResponse(200, ['id' => 'sesn_01']),
            $this->claudeAgentJsonResponse(200, []),
            $this->claudeAgentJsonResponse(200, [
                'data' => [
                    [
                        'id' => 'sevt_2',
                        'type' => 'session.status_idle',
                        'stop_reason' => ['type' => 'requires_action', 'event_ids' => ['sevt_missing']],
                    ],
                ],
                'next_page' => null,
            ]),
        ]);
    }

    /**
     * A non-successful outcome must never come back as bare text — an empty or partial reply that
     * reads as "the agent answered" is exactly the failure this connector is built to avoid.
     */
    public function testAPausedBudgetIsReportedRatherThanReturnedAsAnAnswer(): void
    {
        $agent = $this->makeAgent();
        $session = $this->makeSession($agent);

        $answer = $this->runTurn($agent, $session, [
            $this->claudeAgentJsonResponse(200, ['id' => 'agent_01', 'version' => 1]),
            $this->claudeAgentJsonResponse(200, ['id' => 'sesn_01']),
            $this->claudeAgentJsonResponse(200, []),
            $this->claudeAgentJsonResponse(200, [
                'data' => [
                    ['id' => 'sevt_1', 'type' => 'session.status_idle', 'stop_reason' => ['type' => 'budget_reached']],
                ],
                'next_page' => null,
            ]),
        ]);

        $this->assertStringContainsString('spend limit', $answer);
    }

    /**
     * @return list<array<string, mixed>> A drain that ends the turn immediately.
     */
    private function idleTurn(string $prefix = 'sevt'): array
    {
        return [
            $this->claudeAgentJsonResponse(200, []),
            $this->claudeAgentJsonResponse(200, [
                'data' => [
                    ['id' => $prefix . '_1', 'type' => 'agent.message', 'content' => [['type' => 'text', 'text' => 'ok']]],
                    ['id' => $prefix . '_2', 'type' => 'session.status_idle', 'stop_reason' => ['type' => 'end_turn']],
                ],
                'next_page' => null,
            ]),
        ];
    }

    /**
     * A session freezes its toolset and permission policies at creation, so re-pushing the agent is
     * not enough on its own: without rotating the session, an existing conversation keeps the old
     * tools forever and every new capability we add is silently invisible to it.
     */
    public function testANewAgentVersionOpensAFreshSession(): void
    {
        $agent = $this->makeAgent();
        $session = $this->makeSession($agent);

        $this->runTurn($agent, $session, [
            $this->claudeAgentJsonResponse(200, ['id' => 'agent_01', 'version' => 1]),
            $this->claudeAgentJsonResponse(200, ['id' => 'sesn_01']),
            ...$this->idleTurn(),
        ]);

        $this->assertSame('sesn_01', OpenSessionAction::storedSessionId($session->refresh()));

        // Moves the spec fingerprint, which is what makes the next turn re-push the agent.
        $agent->description = 'Now with a different brief.';
        $agent->saveOrFail();

        $this->runTurn($agent, $session, [
            $this->claudeAgentJsonResponse(200, ['id' => 'agent_01', 'version' => 2]),
            // The superseded session is archived before the replacement is created.
            $this->claudeAgentJsonResponse(200, ['id' => 'sesn_01', 'status' => 'terminated']),
            $this->claudeAgentJsonResponse(200, ['id' => 'sesn_02']),
            ...$this->idleTurn('sevu'),
        ]);

        $this->assertSame('sesn_02', OpenSessionAction::storedSessionId($session->refresh()));
    }

    /**
     * The other half: an unchanged agent must keep its session, or every turn would start a new
     * sandbox and lose the conversation. The mock queue has no create-session response, so opening
     * one would throw.
     */
    public function testAnUnchangedAgentKeepsItsSession(): void
    {
        $agent = $this->makeAgent();
        $session = $this->makeSession($agent);

        $this->runTurn($agent, $session, [
            $this->claudeAgentJsonResponse(200, ['id' => 'agent_01', 'version' => 1]),
            $this->claudeAgentJsonResponse(200, ['id' => 'sesn_01']),
            ...$this->idleTurn(),
        ]);

        // The events endpoint replays the whole history, so the second turn's page carries the first
        // turn's events too — that is what the stored cursor skips forward past.
        $this->runTurn($agent->refresh(), $session->refresh(), [
            $this->claudeAgentJsonResponse(200, []),
            $this->claudeAgentJsonResponse(200, [
                'data' => [
                    ['id' => 'sevt_1', 'type' => 'agent.message', 'content' => [['type' => 'text', 'text' => 'ok']]],
                    ['id' => 'sevt_2', 'type' => 'session.status_idle', 'stop_reason' => ['type' => 'end_turn']],
                    ['id' => 'sevu_1', 'type' => 'agent.message', 'content' => [['type' => 'text', 'text' => 'again']]],
                    ['id' => 'sevu_2', 'type' => 'session.status_idle', 'stop_reason' => ['type' => 'end_turn']],
                ],
                'next_page' => null,
            ]),
        ]);

        $this->assertSame('sesn_01', OpenSessionAction::storedSessionId($session->refresh()));
    }
}
