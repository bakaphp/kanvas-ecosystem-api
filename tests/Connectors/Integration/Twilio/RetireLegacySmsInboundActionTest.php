<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Twilio;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Communication\Connections\Models\Connection;
use Kanvas\Connectors\Twilio\Actions\RetireLegacySmsInboundAction;
use Kanvas\Connectors\Twilio\Enums\ConfigurationEnum;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Workflow\Rules\Models\Rule;
use Tests\Communication\Concerns\BuildsCommunicationFixtures;
use Tests\Communication\Concerns\BuildsLegacyInboundFixtures;
use Tests\TestCase;

/**
 * The hand-over when a company's number moves onto a connection: the agent its old rule named keeps
 * answering, and nothing from before answers a second time.
 */
final class RetireLegacySmsInboundActionTest extends TestCase
{
    use BuildsCommunicationFixtures;
    use BuildsLegacyInboundFixtures;
    use DatabaseTransactions;

    protected $connectionsToTransact = [null, 'workflow', 'crm', 'social', 'intelligence'];

    private const string NUMBER = '+18095550950';
    private const string AGENT_NUMBER = '+18095550951';

    protected function setUp(): void
    {
        parent::setUp();

        $this->forgetLegacyResponderRules(RetireLegacySmsInboundAction::RESPONDER_ACTIVITY);
        $this->currentCompany()->set(ConfigurationEnum::TWILIO_FROM_PHONE_NUMBER->value, self::NUMBER);
    }

    /**
     * Company settings outlive the test transaction (Redis and the ecosystem connection).
     */
    protected function tearDown(): void
    {
        $this->currentCompany()->del(ConfigurationEnum::TWILIO_FROM_PHONE_NUMBER->value);

        parent::tearDown();
    }

    public function testTheAgentTheRuleNamedTakesTheNumberAndTheRuleGoes(): void
    {
        $agent = $this->agent();
        $rule = $this->legacyResponderRule(RetireLegacySmsInboundAction::RESPONDER_ACTIVITY, $agent);
        $receiver = $this->legacyReceiver(RetireLegacySmsInboundAction::RECEIVER_JOBS[0]);
        $connection = $this->connection('fake', self::NUMBER);

        $retired = new RetireLegacySmsInboundAction($connection)->execute();

        $this->assertSame(self::NUMBER, $agent->fresh()->smsPhoneNumber());
        $this->assertSame($agent->getId(), $connection->fresh()->agent()?->getId());
        $this->assertTrue((bool) $rule->fresh()->is_deleted);
        $this->assertFalse((bool) $receiver->fresh()->is_active);
        $this->assertContains('rule ' . $rule->name, $retired);
    }

    public function testANumberAnAgentAlreadyOwnsIsNotHandedToTheRulesAgent(): void
    {
        $owner = $this->agent();
        $rulesAgent = $this->agent();
        $this->legacyResponderRule(RetireLegacySmsInboundAction::RESPONDER_ACTIVITY, $rulesAgent);
        $connection = $this->connection('fake', self::NUMBER);
        $connection->forceFill(['config' => ['agent_id' => $owner->getId()]])->saveOrFail();

        new RetireLegacySmsInboundAction($connection)->execute();

        $this->assertNull($rulesAgent->fresh()->smsPhoneNumber());
        $this->assertSame($owner->getId(), $connection->fresh()->agent()?->getId());
    }

    public function testAnAgentsOwnNumberLeavesTheRuleForTheSendingNumber(): void
    {
        $owner = $this->agent();
        $rulesAgent = $this->agent();
        $rule = $this->legacyResponderRule(RetireLegacySmsInboundAction::RESPONDER_ACTIVITY, $rulesAgent);
        $agentsNumber = $this->connection('fake', self::AGENT_NUMBER);
        $agentsNumber->forceFill(['config' => ['agent_id' => $owner->getId()]])->saveOrFail();
        $sendingNumber = $this->connection('fake', self::NUMBER);

        new RetireLegacySmsInboundAction($agentsNumber)->execute();

        $this->assertFalse((bool) $rule->fresh()->is_deleted);

        new RetireLegacySmsInboundAction($sendingNumber)->execute();

        $this->assertTrue((bool) $rule->fresh()->is_deleted);
        $this->assertSame(self::NUMBER, $rulesAgent->fresh()->smsPhoneNumber());
    }

    public function testRunningItAgainRetiresNothing(): void
    {
        $this->legacyResponderRule(RetireLegacySmsInboundAction::RESPONDER_ACTIVITY, $this->agent());
        $connection = $this->connection('fake', self::NUMBER);

        new RetireLegacySmsInboundAction($connection)->execute();

        $this->assertSame([], new RetireLegacySmsInboundAction(Connection::query()->findOrFail($connection->getId()))->execute());
    }

    private function agent(): Agent
    {
        return Agent::factory()
            ->withAppId($this->currentApp()->getId())
            ->withCompanyId($this->currentCompany()->getId())
            ->create(['sms_config' => null]);
    }
}
