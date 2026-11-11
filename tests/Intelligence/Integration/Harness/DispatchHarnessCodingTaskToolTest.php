<?php

declare(strict_types=1);

namespace Tests\Intelligence\Integration\Harness;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Connectors\OpenCode\Enums\ConfigurationEnum;
use Kanvas\Intelligence\Agents\Enums\ToolOutcomeEnum;
use Kanvas\Intelligence\Agents\Neuron\Tools\Harness\DispatchHarnessCodingTaskTool;
use PHPUnit\Framework\Attributes\Group;
use Tests\Intelligence\Integration\Harness\Concerns\CreatesHarnessAgents;
use Tests\TestCase;

/**
 * What the model is handed when a dispatch fails is what it repeats to the person. A refusal it can act
 * on (bad slug, empty brief, cap reached) keeps its text; anything else is an internal fault and must
 * never surface as exception text — a raw `ModelNotFoundException` once did, in a customer's chat.
 *
 * Serial for the same reason as `DispatchHarnessTaskActionTest`: the unroutable endpoint is an app
 * setting that lives in Redis as well as `ecosystem`.
 */
#[Group('serial')]
final class DispatchHarnessCodingTaskToolTest extends TestCase
{
    use CreatesHarnessAgents;
    use DatabaseTransactions;

    protected $connectionsToTransact = ['mysql', 'ecosystem', 'intelligence', 'social'];

    private const string UNROUTABLE_ENDPOINT = 'http://127.0.0.1:1';

    /** @var array<string, mixed> */
    private array $originalAppSettings = [];

    protected function setUp(): void
    {
        parent::setUp();

        [$app] = $this->context();

        foreach ([ConfigurationEnum::STATIC_ENDPOINT, ConfigurationEnum::STATIC_PASSWORD] as $setting) {
            $this->originalAppSettings[$setting->value] = $app->get($setting->value);
        }
    }

    protected function tearDown(): void
    {
        [$app] = $this->context();

        foreach ($this->originalAppSettings as $key => $value) {
            $value === null ? $app->del($key) : $app->set($key, $value);
        }

        parent::tearDown();
    }

    public function testAnInternalFaultIsReportedWithoutItsExceptionText(): void
    {
        [$app, $company, $user] = $this->context();
        $app->set(ConfigurationEnum::STATIC_ENDPOINT->value, self::UNROUTABLE_ENDPOINT);
        $app->set(ConfigurationEnum::STATIC_PASSWORD->value, 'not-a-real-password');

        $result = new DispatchHarnessCodingTaskTool($this->makeAgent($app, $company, $user), requestedBy: $user)
            ->__invoke(task: 'Make the flaky harness test deterministic');

        $this->assertFalse($result['success']);
        $this->assertSame(ToolOutcomeEnum::PROVIDER_ERROR->value, $result['outcome']);
        $this->assertSame(DispatchHarnessCodingTaskTool::INTERNAL_FAILURE, $result['error']);
        $this->assertStringNotContainsString('Harness request failed', json_encode($result));
        $this->assertStringContainsString('Do NOT quote any error text', $result['note']);
    }

    public function testARefusalTheModelCanActOnKeepsItsMessage(): void
    {
        [$app, $company, $user] = $this->context();

        $result = new DispatchHarnessCodingTaskTool($this->makeAgent($app, $company, $user), requestedBy: $user)
            ->__invoke(task: "   \n ");

        $this->assertFalse($result['success']);
        $this->assertSame('A coding task needs a description', $result['error']);
    }
}
