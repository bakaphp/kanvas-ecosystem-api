<?php

declare(strict_types=1);

namespace Tests\GraphQL\Intelligence;

use Illuminate\Support\Facades\Bus;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Hermes\Jobs\DeployAgentIntegrationConfigJob;
use Kanvas\CustomFields\Models\AppsCustomFields;
use Kanvas\Intelligence\Agents\Models\Agent;
use Tests\TestCase;

class AgentIntegrationConfigTest extends TestCase
{
    private function makeAgent(): Agent
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        return Agent::factory()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->create([
                'user_id' => $user->getId(),
                'is_active' => true,
            ]);
    }

    public function testSetAgentIntegrationConfigPersistsCustomFields(): void
    {
        $agent = $this->makeAgent();

        $googleConfig = [
            'client_id' => 'client-abc',
            'client_secret' => 'secret-def',
            'refresh_token' => 'refresh-xyz',
        ];
        $jiraConfig = [
            'api_url' => 'https://example.atlassian.net',
            'api_token' => 'tok-123',
            'username' => 'alice@example.com',
        ];

        $response = $this->graphQL('
            mutation($input: SetAgentIntegrationConfigInput!) {
                setAgentIntegrationConfig(input: $input) {
                    id
                }
            }
        ', [
            'input' => [
                'agent_id' => (string) $agent->getId(),
                'config' => [
                    ['key' => 'GOOGLE', 'value' => $googleConfig],
                    ['key' => 'JIRA', 'value' => $jiraConfig],
                ],
            ],
        ])->assertSuccessful();

        $this->assertSame((string) $agent->getId(), $response->json('data.setAgentIntegrationConfig.id'));

        $rows = AppsCustomFields::query()
            ->where('model_name', Agent::class)
            ->where('entity_id', $agent->getId())
            ->whereIn('name', ['integration_google', 'integration_jira'])
            ->pluck('value', 'name')
            ->all();

        $this->assertSame($googleConfig, $rows['integration_google'] ?? null);
        $this->assertSame($jiraConfig, $rows['integration_jira'] ?? null);
    }

    public function testSetAgentIntegrationConfigDispatchesDeployJobWithChangedKeys(): void
    {
        Bus::fake([DeployAgentIntegrationConfigJob::class]);

        $agent = $this->makeAgent();

        $this->graphQL('
            mutation($input: SetAgentIntegrationConfigInput!) {
                setAgentIntegrationConfig(input: $input) {
                    id
                }
            }
        ', [
            'input' => [
                'agent_id' => (string) $agent->getId(),
                'config' => [
                    ['key' => 'GOOGLE', 'value' => [
                        'client_id' => 'cid',
                        'client_secret' => 'csecret',
                        'refresh_token' => 'rtok',
                    ]],
                ],
            ],
        ])->assertSuccessful();

        Bus::assertDispatched(
            DeployAgentIntegrationConfigJob::class,
            function (DeployAgentIntegrationConfigJob $job) use ($agent): bool {
                $reflection = new \ReflectionClass($job);

                $agentProp = $reflection->getProperty('agent');
                $keysProp = $reflection->getProperty('changedKeys');

                /** @var Agent $jobAgent */
                $jobAgent = $agentProp->getValue($job);
                /** @var array<int, string> $jobKeys */
                $jobKeys = $keysProp->getValue($job);

                return $jobAgent->getId() === $agent->getId()
                    && $jobKeys === ['integration_google'];
            }
        );
    }

    public function testSetAgentIntegrationConfigRejectsUnknownKey(): void
    {
        $agent = $this->makeAgent();

        $response = $this->graphQL('
            mutation($input: SetAgentIntegrationConfigInput!) {
                setAgentIntegrationConfig(input: $input) {
                    id
                }
            }
        ', [
            'input' => [
                'agent_id' => (string) $agent->getId(),
                'config' => [
                    ['key' => 'SLACK', 'value' => ['bot_token' => 'xoxb-nope']],
                ],
            ],
        ]);

        $this->assertNotEmpty($response->json('errors'), 'Unknown enum value should be rejected');
        $this->assertNull(
            AppsCustomFields::query()
                ->where('model_name', Agent::class)
                ->where('entity_id', $agent->getId())
                ->where('name', 'integration_slack')
                ->first(),
        );
    }
}
