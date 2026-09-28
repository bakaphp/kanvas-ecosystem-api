<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Mailgun;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Mailgun\Enums\ConfigurationEnum;
use Kanvas\Connectors\Mailgun\Enums\CustomFieldEnum;
use Kanvas\Connectors\Mailgun\Jobs\ProvisionAgentMailboxJob;
use Kanvas\Connectors\Mailgun\Services\AgentMailboxService;
use Kanvas\Connectors\Mailgun\Webhooks\AgentInboxWebhookJob;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Models\AgentType;
use Kanvas\Intelligence\Agents\Neuron\CRM\SalesAgent;
use Kanvas\Intelligence\Agents\Neuron\CRM\SalesManagerAgent;
use Kanvas\Intelligence\Agents\Neuron\Tools\System\ProvisionMyEmailInboxTool;
use Kanvas\Users\Models\Users;
use Kanvas\Workflow\Models\WorkflowAction;
use Tests\TestCase;

/**
 * Who is entitled to a mailbox without asking for one, and the once-only guarantee.
 *
 * The policy is asserted on the service rather than on a creation hook: `shouldAutoProvision()` is
 * the single decision point, so wherever it ends up being called from, this is what it will answer.
 */
final class AgentMailboxAutoProvisionTest extends TestCase
{
    private const string DOMAIN = 'agents.kanvas.test';

    private Apps $kanvasApp;
    private Companies $company;
    private Users $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = auth()->user();
        $this->company = $this->user->getCurrentCompany();
        // Own app row: paratest runs test classes concurrently against one database, and the shared
        // app's Mailgun settings are written and deleted by sibling classes mid-test.
        $this->kanvasApp = $this->dedicatedApp();

        $this->configureMailgun();

        WorkflowAction::firstOrCreate(
            ['model_name' => AgentInboxWebhookJob::class],
            ['name' => 'AgentInboxWebhookJob']
        );
    }

    private function dedicatedApp(): Apps
    {
        $uniqueId = uniqid('mailgunauto', true);

        $app = new Apps();
        $app->name = 'Mailgun Auto Provision Test ' . $uniqueId;
        $app->url = 'https://' . $uniqueId . '.example.com';
        $app->domain = $uniqueId . '.example.com';
        $app->description = 'Agent mailbox auto-provision test app';
        $app->is_actived = 1;
        $app->ecosystem_auth = 0;
        $app->payments_active = 0;
        $app->is_public = 0;
        $app->domain_based = 0;
        $app->saveOrFail();

        return $app;
    }

    public function testAnInternalAgentQualifiesForAMailbox(): void
    {
        $this->assertTrue(
            new AgentMailboxService()->shouldAutoProvision($this->agent(SalesManagerAgent::class))
        );
    }

    public function testACustomerFacingAgentDoesNot(): void
    {
        // It already speaks through the company's shared lead inbox — a second public address per
        // persona buys nothing and multiplies Mailgun routes.
        $this->assertFalse(
            new AgentMailboxService()->shouldAutoProvision($this->agent(SalesAgent::class))
        );
    }

    public function testASubAgentDoesNot(): void
    {
        // A sub-agent is a tool another agent calls, not a correspondent.
        $this->assertFalse(
            new AgentMailboxService()->shouldAutoProvision($this->agent(SalesManagerAgent::class, isSubAgent: true))
        );
    }

    public function testACompanyWithoutTheMailgunSetupDoesNot(): void
    {
        $agent = $this->agent(SalesManagerAgent::class, company: Companies::factory()->create());
        $this->kanvasApp->del(ConfigurationEnum::DOMAIN->value);

        // Skipped, not failed: the company can still provision by hand once it has a domain.
        $this->assertFalse(new AgentMailboxService()->shouldAutoProvision($agent));
    }

    public function testAnAgentThatAlreadyHasOneDoesNot(): void
    {
        $agent = $this->agent(SalesManagerAgent::class);
        $agent->set(CustomFieldEnum::MAILBOX_ADDRESS->value, 'taken@' . self::DOMAIN);

        $this->assertFalse(new AgentMailboxService()->shouldAutoProvision($agent));
    }

    public function testTheJobIsANoopWhenTheAgentAlreadyHasAnAddress(): void
    {
        $this->fakeMailgun();
        $agent = $this->agent(SalesManagerAgent::class);
        $agent->set(CustomFieldEnum::MAILBOX_ADDRESS->value, 'taken@' . self::DOMAIN);

        new ProvisionAgentMailboxJob($agent)->handle();

        // Re-checked in the job because the queue runs later — by then another path may have given
        // this agent its one address.
        $this->assertSame('taken@' . self::DOMAIN, $agent->get(CustomFieldEnum::MAILBOX_ADDRESS->value));
        Http::assertNothingSent();
    }

    public function testTheToolJustRemindsTheAgentOfItsAddressOnceItHasOne(): void
    {
        $this->fakeMailgun();
        $agent = $this->agent(SalesManagerAgent::class);
        $address = 'sofia-' . strtolower(Str::random(5)) . '@' . self::DOMAIN;
        $agent->set(CustomFieldEnum::MAILBOX_ADDRESS->value, $address);

        $result = new ProvisionMyEmailInboxTool($agent)
            ->withContext($this->kanvasApp, $this->company, $this->user)
            ->__invoke();

        $this->assertSame('success', $result['status']);
        $this->assertTrue($result['already_provisioned']);
        $this->assertSame($address, $result['address']);
        // A read-back must not touch Mailgun, and must not need an admin in the room.
        Http::assertNothingSent();
    }

    public function testTheToolRefusesToCreateOneWithoutAnAdminInTheConversation(): void
    {
        $this->fakeMailgun();

        $result = new ProvisionMyEmailInboxTool($this->agent(SalesManagerAgent::class))
            ->withContext($this->kanvasApp, $this->company, $this->user)
            ->forRequestingUser(null)
            ->__invoke();

        $this->assertSame('error', $result['status']);
        Http::assertNothingSent();
    }

    private function agent(string $handler, bool $isSubAgent = false, ?Companies $company = null): Agent
    {
        $agentType = AgentType::factory()
            ->withAppId($this->kanvasApp->getId())
            ->create([
                'provider' => 'neuron',
                'handler' => $handler,
            ]);

        return Agent::factory()
            ->withAppId($this->kanvasApp->getId())
            ->withCompanyId(($company ?? $this->company)->getId())
            ->create([
                'name' => 'Sofia' . Str::random(8),
                'user_id' => $this->user->getId(),
                'agent_type_id' => $agentType->getId(),
                'is_sub_agent' => $isSubAgent,
            ]);
    }

    private function configureMailgun(): void
    {
        $this->kanvasApp->set(ConfigurationEnum::API_KEY->value, 'key-test');
        $this->kanvasApp->set(ConfigurationEnum::DOMAIN->value, self::DOMAIN);
        $this->kanvasApp->set(ConfigurationEnum::WEBHOOK_SIGNING_KEY->value, 'signing-key-test');
    }

    private function fakeMailgun(): void
    {
        Http::fake(['*' => Http::response([])]);
    }
}
