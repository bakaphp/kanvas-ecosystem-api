<?php

declare(strict_types=1);

namespace Tests\Connectors\ClaudeAgent;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Queue;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\ClaudeAgent\Actions\DispatchLongTaskAction;
use Kanvas\Connectors\ClaudeAgent\Actions\PullSessionOutputsAction;
use Kanvas\Connectors\ClaudeAgent\Enums\ConfigurationEnum;
use Kanvas\NervousSystem\Plan\Models\Plan;
use Tests\Connectors\Traits\HasClaudeAgentConfiguration;
use Tests\TestCase;

/**
 * Without artifact retrieval the sandbox is a one-way door — the agent builds a real file, describes
 * it accurately, and it dies with the session. These cover the way back.
 */
final class PullSessionOutputsActionTest extends TestCase
{
    use DatabaseTransactions;
    use HasClaudeAgentConfiguration;

    /** Settings live on mysql; plans and tasks on intelligence; filesystem rows on the default. */
    protected array $connectionsToTransact = ['mysql', 'intelligence'];

    private Apps $currentApp;
    private Companies $currentCompany;

    protected function setUp(): void
    {
        parent::setUp();

        $this->currentApp = app(Apps::class);
        $this->currentCompany = static::$cachedUser->getCurrentCompany();
        $this->configureClaudeAgent($this->currentApp, $this->currentCompany);
        $this->currentCompany->set(ConfigurationEnum::ENVIRONMENT_ID->value, 'env_cached');
        Queue::fake();
    }

    private function planForTask(): Plan
    {
        $task = new DispatchLongTaskAction(
            agent: $this->makeClaudeAgent($this->currentApp, $this->currentCompany),
            brief: 'Produce a report.',
            requestedBy: static::$cachedUser,
            client: $this->claudeAgentClientReturning($this->currentApp, $this->currentCompany, [
                $this->claudeAgentJsonResponse(200, ['id' => 'agent_01', 'version' => 1]),
                $this->claudeAgentJsonResponse(200, ['id' => 'sesn_01']),
            ]),
        )->execute();

        return $task->plan;
    }

    public function testItDownloadsEachOutputAndAttachesItToThePlan(): void
    {
        $plan = $this->planForTask();

        $client = $this->claudeAgentClientReturning($this->currentApp, $this->currentCompany, [
            $this->claudeAgentJsonResponse(200, [
                'data' => [
                    ['id' => 'file_01', 'filename' => 'report.csv', 'size_bytes' => 12],
                ],
            ]),
            $this->claudeAgentRawResponse(200, "sku,price\nA-1,10\n"),
        ]);

        $attached = new PullSessionOutputsAction($plan, static::$cachedUser, 'sesn_01', $client)->execute();

        $this->assertSame(['report.csv'], $attached);
        $this->assertGreaterThan(0, $plan->getFiles()->count());
    }

    /**
     * There is an indexing lag between the session going idle and outputs appearing, so an empty
     * page is a normal outcome, not an error.
     */
    public function testAnEmptyListIsNotAFailure(): void
    {
        $plan = $this->planForTask();

        $client = $this->claudeAgentClientReturning($this->currentApp, $this->currentCompany, [
            $this->claudeAgentJsonResponse(200, ['data' => []]),
        ]);

        $this->assertSame([], new PullSessionOutputsAction($plan, static::$cachedUser, 'sesn_01', $client)->execute());
    }

    /**
     * One bad file must not cost the others — a task that produced good output should not lose it
     * because a single download failed.
     */
    public function testOneFailedDownloadDoesNotLoseTheRest(): void
    {
        $plan = $this->planForTask();

        $client = $this->claudeAgentClientReturning($this->currentApp, $this->currentCompany, [
            $this->claudeAgentJsonResponse(200, [
                'data' => [
                    ['id' => 'file_bad', 'filename' => 'broken.txt'],
                    ['id' => 'file_ok', 'filename' => 'good.txt'],
                ],
            ]),
            $this->claudeAgentJsonResponse(500, ['error' => ['message' => 'boom']]),
            $this->claudeAgentRawResponse(200, 'usable content'),
        ]);

        $this->assertSame(['good.txt'], new PullSessionOutputsAction($plan, static::$cachedUser, 'sesn_01', $client)->execute());
    }

    /**
     * A listing failure must never fail the task — the answer the agent produced still stands.
     */
    public function testAListingFailureReturnsNothingRatherThanThrowing(): void
    {
        $plan = $this->planForTask();

        $client = $this->claudeAgentClientReturning($this->currentApp, $this->currentCompany, [
            $this->claudeAgentJsonResponse(503, ['error' => ['message' => 'unavailable']]),
        ]);

        $this->assertSame([], new PullSessionOutputsAction($plan, static::$cachedUser, 'sesn_01', $client)->execute());
    }

    /**
     * Nowhere to attach to — an ad-hoc turn with no session, a task whose plan is gone. The guard
     * lives here rather than in each caller, so the empty mock queue proves no request is made.
     */
    public function testNothingToAttachToIsANoOp(): void
    {
        $client = $this->claudeAgentClientReturning($this->currentApp, $this->currentCompany, []);

        $this->assertSame([], new PullSessionOutputsAction(null, static::$cachedUser, 'sesn_01', $client)->execute());
        $this->assertSame([], new PullSessionOutputsAction($this->planForTask(), null, 'sesn_01', $client)->execute());
    }

    public function testEntriesMissingAnIdOrFilenameAreSkipped(): void
    {
        $plan = $this->planForTask();

        $client = $this->claudeAgentClientReturning($this->currentApp, $this->currentCompany, [
            $this->claudeAgentJsonResponse(200, [
                'data' => [
                    ['filename' => 'no-id.txt'],
                    ['id' => 'file_02'],
                ],
            ]),
        ]);

        $this->assertSame([], new PullSessionOutputsAction($plan, static::$cachedUser, 'sesn_01', $client)->execute());
    }
}
