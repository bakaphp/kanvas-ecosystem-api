<?php

declare(strict_types=1);

namespace Tests\Intelligence\Agents;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Communication\Connections\Enums\SetupStatusEnum;
use Kanvas\Intelligence\Agents\Actions\Phone\ReportAgentTextConnectionAction;
use Kanvas\Intelligence\Agents\Models\Agent;
use Tests\Communication\Concerns\BuildsCommunicationFixtures;
use Tests\TestCase;

/**
 * What an admin sees for an agent's text number is the state of the connection the number became,
 * never a guess made before the company's connections were reconciled.
 */
final class AgentTextConnectionStatusTest extends TestCase
{
    use BuildsCommunicationFixtures;
    use DatabaseTransactions;

    protected $connectionsToTransact = [null, 'intelligence', 'workflow'];

    private string $number;

    protected function setUp(): void
    {
        parent::setUp();

        $this->useFakeProvider();
        $this->number = '+1809' . fake()->unique()->numerify('#######');
    }

    public function testAReadyConnectionTheAgentOwnsIsWired(): void
    {
        $agent = $this->agent($this->number);
        $this->connection('fake', $this->number, ['config' => ['agent_id' => $agent->getId()]]);

        $result = new ReportAgentTextConnectionAction($agent)->execute();

        $this->assertSame('wired', $result['status']);
        $this->assertSame('wired', $agent->fresh()->sms_config['inbound_webhook']['status']);
    }

    public function testAConnectionStillBeingSetUpIsPendingNotWired(): void
    {
        $agent = $this->agent($this->number);
        $this->connection('fake', $this->number, [
            'config' => ['agent_id' => $agent->getId()],
            'setup_status' => SetupStatusEnum::PENDING_SETUP,
        ]);

        $this->assertSame('pending', new ReportAgentTextConnectionAction($agent)->execute()['status']);
    }

    public function testAConnectionWhoseSetupFailedIsAnError(): void
    {
        $agent = $this->agent($this->number);
        $this->connection('fake', $this->number, [
            'config' => ['agent_id' => $agent->getId()],
            'setup_status' => SetupStatusEnum::FAILED,
            'last_error' => 'the vendor refused the webhook',
        ]);

        $result = new ReportAgentTextConnectionAction($agent)->execute();

        $this->assertSame('error', $result['status']);
        $this->assertStringContainsString('the vendor refused the webhook', $result['message']);
    }

    public function testANumberAnotherAgentAnswersIsNotWired(): void
    {
        $agent = $this->agent($this->number);
        $this->connection('fake', $this->number, ['config' => ['agent_id' => $this->agent(null)->getId()]]);

        $result = new ReportAgentTextConnectionAction($agent)->execute();

        $this->assertSame('error', $result['status']);
        $this->assertStringContainsString('another agent', $result['message']);
    }

    public function testANumberThatIsNoConnectionAsksForTheIntegration(): void
    {
        $result = new ReportAgentTextConnectionAction($this->agent($this->number))->execute();

        $this->assertSame('error', $result['status']);
        $this->assertStringContainsString('Connect the Twilio integration', $result['message']);
    }

    public function testAnAgentWithoutATextNumberKeepsANullConfig(): void
    {
        $agent = $this->agent(null);

        $this->assertSame('idle', new ReportAgentTextConnectionAction($agent)->execute()['status']);
        $this->assertNull($agent->fresh()->sms_config);
    }

    private function agent(?string $sms): Agent
    {
        return Agent::factory()
            ->withAppId($this->currentApp()->getId())
            ->withCompanyId($this->currentCompany()->getId())
            ->create(['sms_config' => $sms !== null ? ['phone_number' => $sms] : null]);
    }
}
