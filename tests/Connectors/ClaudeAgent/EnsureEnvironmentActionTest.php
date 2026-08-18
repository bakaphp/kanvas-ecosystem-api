<?php

declare(strict_types=1);

namespace Tests\Connectors\ClaudeAgent;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\ClaudeAgent\Actions\EnsureEnvironmentAction;
use Kanvas\Connectors\ClaudeAgent\Client;
use Kanvas\Connectors\ClaudeAgent\Enums\ConfigurationEnum;
use Kanvas\Connectors\ClaudeAgent\Exceptions\ClaudeAgentApiException;
use Tests\Connectors\Traits\HasClaudeAgentConfiguration;
use Tests\TestCase;

final class EnsureEnvironmentActionTest extends TestCase
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
        $this->currentCompany->set(ConfigurationEnum::ENVIRONMENT_ID->value, '');
    }

    public function testCreatesTheEnvironmentOnceAndCachesItOnTheCompany(): void
    {
        $client = $this->claudeAgentClientReturning($this->currentApp, $this->currentCompany, [
            $this->claudeAgentJsonResponse(200, ['id' => 'env_01abc', 'name' => 'kanvas']),
        ]);

        $environmentId = new EnsureEnvironmentAction(
            $this->currentApp,
            $this->currentCompany,
            $client,
        )->execute();

        $this->assertSame('env_01abc', $environmentId);
        $this->assertSame(
            'env_01abc',
            $this->currentCompany->get(ConfigurationEnum::ENVIRONMENT_ID->value),
        );
    }

    /**
     * Environment endpoints are capped at 60 RPM / 5 concurrent org-wide, so a cached id must never
     * cost a request. Empty mock queue means any HTTP attempt throws.
     */
    public function testCachedEnvironmentMakesNoHttpCall(): void
    {
        $this->currentCompany->set(ConfigurationEnum::ENVIRONMENT_ID->value, 'env_cached');

        $noCallsAllowed = new Client(
            $this->currentApp,
            $this->currentCompany,
            $this->claudeAgentGuzzleReturning([]),
        );

        $environmentId = new EnsureEnvironmentAction(
            $this->currentApp,
            $this->currentCompany,
            $noCallsAllowed,
        )->execute();

        $this->assertSame('env_cached', $environmentId);
    }

    /**
     * A 409 means we already created it on an earlier run and lost the cached id. Recovering by name
     * is what stops us leaking a second environment per company every time settings are cleared.
     */
    public function testDuplicateNameRecoversTheExistingEnvironmentByName(): void
    {
        $expectedName = sprintf(
            'kanvas-app-%d-company-%d',
            $this->currentApp->getId(),
            $this->currentCompany->getId(),
        );

        $client = $this->claudeAgentClientReturning($this->currentApp, $this->currentCompany, [
            $this->claudeAgentJsonResponse(409, [
                'type' => 'error',
                'error' => ['type' => 'invalid_request_error', 'message' => 'name already exists'],
            ]),
            $this->claudeAgentJsonResponse(200, [
                'data' => [
                    ['id' => 'env_other', 'name' => 'someone-elses'],
                    ['id' => 'env_recovered', 'name' => $expectedName],
                ],
            ]),
        ]);

        $environmentId = new EnsureEnvironmentAction(
            $this->currentApp,
            $this->currentCompany,
            $client,
        )->execute();

        $this->assertSame('env_recovered', $environmentId);
        $this->assertSame(
            'env_recovered',
            $this->currentCompany->get(ConfigurationEnum::ENVIRONMENT_ID->value),
        );
    }

    public function testNonConflictErrorsPropagate(): void
    {
        $client = $this->claudeAgentClientReturning($this->currentApp, $this->currentCompany, [
            $this->claudeAgentJsonResponse(401, [
                'type' => 'error',
                'error' => ['type' => 'authentication_error', 'message' => 'invalid x-api-key'],
            ]),
        ]);

        try {
            new EnsureEnvironmentAction($this->currentApp, $this->currentCompany, $client)->execute();
            $this->fail('Expected a ClaudeAgentApiException.');
        } catch (ClaudeAgentApiException $e) {
            $this->assertSame(401, $e->status);
        }
    }
}
