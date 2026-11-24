<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Mailgun;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Communication\Connections\Models\Connection;
use Kanvas\Connectors\Mailgun\Actions\RetireLegacyEmailInboundAction;
use Kanvas\Connectors\Mailgun\Enums\ConfigurationEnum;
use Kanvas\Connectors\Mailgun\Enums\CustomFieldEnum;
use Kanvas\Connectors\Mailgun\Enums\MailboxAccessEnum;
use Kanvas\Connectors\Mailgun\Enums\ReceiverConfigurationEnum;
use Kanvas\Connectors\Mailgun\Services\AgentMailboxService;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Workflow\Rules\Models\Rule;
use Tests\Communication\Concerns\BuildsCommunicationFixtures;
use Tests\Communication\Concerns\BuildsLegacyInboundFixtures;
use Tests\TestCase;

/**
 * The hand-over when a company's addresses move onto connections: the agent its old rule named keeps
 * answering the company's mail as a customer line, and each address only retires what served it.
 */
final class RetireLegacyEmailInboundActionTest extends TestCase
{
    use BuildsCommunicationFixtures;
    use BuildsLegacyInboundFixtures;
    use DatabaseTransactions;

    protected $connectionsToTransact = [null, 'workflow', 'crm', 'social', 'intelligence'];

    private const string DOMAIN = 'mg.retire.test';
    private const string COMPANY_ADDRESS = 'sales@mg.retire.test';
    private const string MAILBOX = 'sofia@mg.retire.test';

    protected function setUp(): void
    {
        parent::setUp();

        $this->currentCompany()->set(ConfigurationEnum::DOMAIN->value, self::DOMAIN);
        $this->currentCompany()->set(ConfigurationEnum::FROM_ADDRESS->value, self::COMPANY_ADDRESS);

        $this->forgetLegacyResponderRules(RetireLegacyEmailInboundAction::RESPONDER_ACTIVITY);
    }

    /**
     * Company settings outlive the test transaction (Redis and the ecosystem connection).
     */
    protected function tearDown(): void
    {
        $this->currentCompany()->del(ConfigurationEnum::DOMAIN->value);
        $this->currentCompany()->del(ConfigurationEnum::FROM_ADDRESS->value);

        parent::tearDown();
    }

    public function testTheRulesAgentAnswersTheCompanysAddressAsACustomerLine(): void
    {
        $agent = $this->agent();
        $rule = $this->legacyResponderRule(RetireLegacyEmailInboundAction::RESPONDER_ACTIVITY, $agent);
        $inbox = $this->legacyReceiver(RetireLegacyEmailInboundAction::COMPANY_INBOX_JOB);
        $connection = $this->connection('mailgun', self::COMPANY_ADDRESS);

        new RetireLegacyEmailInboundAction($connection)->execute();

        $mailboxes = new AgentMailboxService();
        $connection = Connection::query()->findOrFail($connection->getId());

        $this->assertSame(self::COMPANY_ADDRESS, $mailboxes->addressFor($agent));
        $this->assertSame(MailboxAccessEnum::CUSTOMER_LINE, $mailboxes->accessFor($agent));
        $this->assertTrue($connection->agent()->is($agent));
        $this->assertNull($connection->personalOwner(), 'the company line is never the agent\'s own mailbox');
        $this->assertTrue((bool) $rule->fresh()->is_deleted);
        $this->assertFalse((bool) $inbox->fresh()->is_active);
    }

    public function testAnAgentWithAMailboxOfItsOwnDoesNotTakeTheCompanysAddress(): void
    {
        $agent = $this->agent();
        $agent->set(CustomFieldEnum::MAILBOX_ADDRESS->value, self::MAILBOX);
        $this->legacyResponderRule(RetireLegacyEmailInboundAction::RESPONDER_ACTIVITY, $agent);
        $connection = $this->connection('mailgun', self::COMPANY_ADDRESS);

        new RetireLegacyEmailInboundAction($connection)->execute();

        $this->assertSame(self::MAILBOX, new AgentMailboxService()->addressFor($agent));
        $this->assertNull(Connection::query()->findOrFail($connection->getId())->agent());
    }

    public function testAMailboxOnlyRetiresItsOwnReceiverAndLeavesTheRuleAlone(): void
    {
        $rule = $this->legacyResponderRule(RetireLegacyEmailInboundAction::RESPONDER_ACTIVITY, $this->agent());
        $companyInbox = $this->legacyReceiver(RetireLegacyEmailInboundAction::COMPANY_INBOX_JOB);
        $ownInbox = $this->legacyReceiver(
            RetireLegacyEmailInboundAction::AGENT_INBOX_JOB,
            [ReceiverConfigurationEnum::MAILBOX_ADDRESS->value => self::MAILBOX]
        );
        $anotherInbox = $this->legacyReceiver(
            RetireLegacyEmailInboundAction::AGENT_INBOX_JOB,
            [ReceiverConfigurationEnum::MAILBOX_ADDRESS->value => 'luis@' . self::DOMAIN]
        );
        $connection = $this->connection('mailgun', self::MAILBOX);

        new RetireLegacyEmailInboundAction($connection)->execute();

        $this->assertFalse((bool) $ownInbox->fresh()->is_active);
        $this->assertTrue((bool) $anotherInbox->fresh()->is_active);
        $this->assertTrue((bool) $companyInbox->fresh()->is_active);
        $this->assertFalse((bool) $rule->fresh()->is_deleted);
    }

    private function agent(): Agent
    {
        return Agent::factory()
            ->withAppId($this->currentApp()->getId())
            ->withCompanyId($this->currentCompany()->getId())
            ->create();
    }
}
