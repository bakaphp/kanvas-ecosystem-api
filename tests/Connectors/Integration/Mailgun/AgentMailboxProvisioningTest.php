<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Mailgun;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Mailgun\Actions\DisconnectAgentMailboxAction;
use Kanvas\Connectors\Mailgun\Actions\ProvisionAgentMailboxAction;
use Kanvas\Connectors\Mailgun\Enums\ConfigurationEnum;
use Kanvas\Connectors\Mailgun\Enums\CustomFieldEnum;
use Kanvas\Connectors\Mailgun\Enums\MailboxAccessEnum;
use Kanvas\Connectors\Mailgun\Enums\ReceiverConfigurationEnum;
use Kanvas\Connectors\Mailgun\Services\AgentMailboxService;
use Kanvas\Connectors\Mailgun\Webhooks\AgentInboxWebhookJob;
use Kanvas\Enums\AppEnums;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Users\Models\Users;
use Kanvas\Workflow\Models\ReceiverWebhook;
use Kanvas\Workflow\Models\WorkflowAction;
use Tests\TestCase;

final class AgentMailboxProvisioningTest extends TestCase
{
    private const string DOMAIN = 'agents.kanvas.test';

    private Apps $kanvasApp;
    private Companies $company;
    private Users $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->kanvasApp = app(Apps::class);
        $this->user = auth()->user();
        $this->company = $this->user->getCurrentCompany();

        $this->kanvasApp->set(ConfigurationEnum::API_KEY->value, 'key-test');
        $this->company->set(ConfigurationEnum::DOMAIN->value, self::DOMAIN);
        $this->company->set(ConfigurationEnum::WEBHOOK_SIGNING_KEY->value, 'signing-key-test');
        // Custom fields outlive a rollback and every agent here shares one user — a contact_email
        // left by an earlier test would decide this one's outcome.
        $this->user->del('contact_email');

        WorkflowAction::firstOrCreate(
            ['model_name' => AgentInboxWebhookJob::class],
            ['name' => 'AgentInboxWebhookJob']
        );
    }

    /**
     * App/company config outlives the rollback, and a configured Mailgun is what makes
     * CreateAgentAction auto-provision — leaving it set would have unrelated suites dispatching
     * mailbox jobs for every agent they create.
     */
    protected function tearDown(): void
    {
        $this->company->del(ConfigurationEnum::DOMAIN->value);
        $this->company->del(ConfigurationEnum::WEBHOOK_SIGNING_KEY->value);
        $this->kanvasApp->del(ConfigurationEnum::API_KEY->value);

        parent::tearDown();
    }

    public function testProvisioningGivesTheAgentAnAddressAndARouteIntoItsOwnReceiver(): void
    {
        $this->fakeMailgun();
        $agent = $this->agent();

        $mailbox = new ProvisionAgentMailboxAction($agent)->execute();

        $this->assertSame($this->addressOf($agent), $mailbox['address']);
        $this->assertSame('route-1', $mailbox['route_id']);
        $this->assertStringContainsString('/v1/receiver/', $mailbox['receiver_url']);
        $this->assertSame(MailboxAccessEnum::RESTRICTED->value, $mailbox['access']);

        $receiver = $this->receiverOf($agent->refresh());
        $this->assertTrue($receiver->is_active);
        $this->assertSame($agent->getId(), $receiver->configuration[ReceiverConfigurationEnum::AGENT_ID->value]);
        // Without capture_files the attachments are gone by the time the job runs.
        $this->assertTrue($receiver->configuration[ReceiverConfigurationEnum::CAPTURE_FILES->value]);

        Http::assertSent(fn ($request): bool => $request->method() === 'POST'
            && str_contains($request->url(), '/v3/routes')
            && str_contains((string) $request->body(), 'match_recipient("' . $mailbox['address'] . '")')
            && str_contains((string) $request->body(), $mailbox['receiver_url']));
    }

    public function testTheAddressIsRecordedAsTheAgentUsersContactEmail(): void
    {
        $this->fakeMailgun();
        $agent = $this->agent();

        $mailbox = new ProvisionAgentMailboxAction($agent)->execute();

        $this->assertTrue($mailbox['contact_email_set']);
        // NotificationMailTrait delivers to contact_email as well as the primary — this is what puts
        // every Kanvas notification aimed at the agent somewhere the agent can read and answer.
        $this->assertSame($mailbox['address'], $agent->user->get('contact_email'));
    }

    public function testAHumansExistingContactEmailIsNotOverwritten(): void
    {
        $this->fakeMailgun();
        $agent = $this->agent();
        $agent->user->set('contact_email', 'real.person@gmail.test');

        $mailbox = new ProvisionAgentMailboxAction($agent)->execute();

        $this->assertFalse($mailbox['contact_email_set']);
        // Agents can still share a user with a human, whose contact_email is their password-recovery
        // fallback. Clobbering it locks them out.
        $this->assertSame('real.person@gmail.test', $agent->user->get('contact_email'));
    }

    public function testReprovisioningUpdatesTheSameRouteAndReceiver(): void
    {
        $this->fakeMailgun();
        $agent = $this->agent();

        $first = new ProvisionAgentMailboxAction($agent)->execute();
        $second = new ProvisionAgentMailboxAction($agent->refresh(), MailboxAccessEnum::OPEN)->execute();

        $this->assertSame($first['address'], $second['address']);
        // A second receiver would orphan the URL the Mailgun route already points at.
        $this->assertSame($first['receiver_url'], $second['receiver_url']);
        $this->assertSame(MailboxAccessEnum::OPEN->value, $second['access']);

        Http::assertSent(fn ($request): bool => $request->method() === 'PUT'
            && str_contains($request->url(), '/v3/routes/route-1'));
    }

    public function testASecondAgentWithTheSameNameGetsItsOwnAddress(): void
    {
        $this->fakeMailgun();
        $name = 'Sofia' . Str::random(6);

        $first = new ProvisionAgentMailboxAction($this->agent($name))->execute();
        $twin = new ProvisionAgentMailboxAction($this->agent($name))->execute();

        // A Mailgun route is global to the account: two agents on one address means one of them
        // silently never hears from anyone.
        $this->assertNotSame($first['address'], $twin['address']);
        $this->assertStringStartsWith(Str::slug($name) . '-', $twin['address']);
    }

    public function testRenamingAnAgentDoesNotMoveItsMailbox(): void
    {
        $this->fakeMailgun();
        $agent = $this->agent('Sofia' . Str::random(6));

        $original = new ProvisionAgentMailboxAction($agent)->execute();

        $agent->name = 'Sofia Renamed';
        $agent->saveOrFail();

        // People already write to the old address; silently moving it drops their mail on the floor.
        $this->assertSame($original['address'], new ProvisionAgentMailboxAction($agent->refresh())->execute()['address']);
    }

    public function testProvisioningFailsWhenTheCompanyHasNoMailgunDomain(): void
    {
        $this->fakeMailgun();
        $this->company->set(ConfigurationEnum::DOMAIN->value, '');
        $this->kanvasApp->set(ConfigurationEnum::DOMAIN->value, '');

        try {
            new ProvisionAgentMailboxAction($this->agent())->execute();
            $this->fail('Provisioning must refuse to invent a domain.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('Mailgun domain', $e->getMessage());
        } finally {
            // Company/app settings outlive a transaction rollback; leaving this blank would break
            // every later test in the suite.
            $this->company->set(ConfigurationEnum::DOMAIN->value, self::DOMAIN);
        }
    }

    public function testProvisioningRefusesWhenTheWebhookSigningKeyIsMissing(): void
    {
        $this->fakeMailgun();
        $appKey = (string) $this->kanvasApp->get(ConfigurationEnum::WEBHOOK_SIGNING_KEY->value);
        $this->kanvasApp->set(ConfigurationEnum::WEBHOOK_SIGNING_KEY->value, '');
        $this->company->set(ConfigurationEnum::WEBHOOK_SIGNING_KEY->value, '');

        try {
            new ProvisionAgentMailboxAction($this->agent())->execute();
            $this->fail('A mailbox whose inbound is rejected 401 is worse than no mailbox.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('signing key', $e->getMessage());
        } finally {
            $this->kanvasApp->set(ConfigurationEnum::WEBHOOK_SIGNING_KEY->value, $appKey);
            $this->company->set(ConfigurationEnum::WEBHOOK_SIGNING_KEY->value, 'signing-key-test');
        }
    }

    public function testDisconnectDeletesTheRouteAndClearsTheAddressButKeepsTheReceiver(): void
    {
        $this->fakeMailgun();
        $agent = $this->agent();
        $provisioned = new ProvisionAgentMailboxAction($agent)->execute();

        new DisconnectAgentMailboxAction($agent->refresh())->execute();

        $agent = $agent->refresh();
        $receiver = $this->receiverOf($agent);

        $this->assertNull(new AgentMailboxService()->addressFor($agent));
        $this->assertNull($agent->get(CustomFieldEnum::ROUTE_ID->value));
        $this->assertFalse($receiver->is_active);
        // Kept: any route the customer built by hand still forwards here.
        $this->assertSame($provisioned['receiver_url'], $receiver->getUrl());
        $this->assertNull($agent->user->get('contact_email'));

        Http::assertSent(fn ($request): bool => $request->method() === 'DELETE'
            && str_contains($request->url(), '/v3/routes/route-1'));
    }

    public function testStatusReflectsTheLifecycle(): void
    {
        $this->fakeMailgun();
        $agent = $this->agent();
        $service = new AgentMailboxService();

        $this->assertNull($service->statusFor($agent));

        new ProvisionAgentMailboxAction($agent)->execute();
        $status = $service->statusFor($agent->refresh());

        $this->assertSame($this->addressOf($agent), $status['address']);
        $this->assertTrue($status['contact_email_set']);

        new DisconnectAgentMailboxAction($agent->refresh())->execute();
        $this->assertNull($service->statusFor($agent->refresh()));
    }

    public function testTheGraphQLSurfaceIsWired(): void
    {
        $this->fakeMailgun();
        $agent = $this->agent();

        // Pin the request to the app the agent lives under, or the resolver's app(Apps::class) is
        // resolved from the request host and getByIdFromCompanyApp can't find the agent.
        $headers = [
            AppEnums::KANVAS_APP_KEY_HEADER->getValue() => $this->kanvasApp->keys()->first()->client_secret_id,
        ];

        $this->graphQL('
            mutation ($id: ID!) {
                provisionAgentMailbox(agent_id: $id, access: RESTRICTED) { address access contact_email_set }
            }
        ', ['id' => $agent->getId()], [], $headers)
            ->assertSuccessful()
            ->assertJsonPath('data.provisionAgentMailbox.address', $this->addressOf($agent))
            ->assertJsonPath('data.provisionAgentMailbox.access', 'RESTRICTED');

        $this->graphQL('
            query ($id: ID!) { agentMailbox(agent_id: $id) { address } }
        ', ['id' => $agent->getId()], [], $headers)
            ->assertSuccessful()
            ->assertJsonPath('data.agentMailbox.address', $this->addressOf($agent));

        $this->graphQL('
            mutation ($id: ID!) { disconnectAgentMailbox(agent_id: $id) }
        ', ['id' => $agent->getId()], [], $headers)
            ->assertSuccessful()
            ->assertJsonPath('data.disconnectAgentMailbox', true);

        $this->graphQL('
            query ($id: ID!) { agentMailbox(agent_id: $id) { address } }
        ', ['id' => $agent->getId()], [], $headers)
            ->assertSuccessful()
            ->assertJsonPath('data.agentMailbox', null);
    }

    private function fakeMailgun(): void
    {
        Http::fake([
            'api.mailgun.net/v3/domains/*' => Http::response(['domain' => ['name' => self::DOMAIN, 'state' => 'active']]),
            // One pattern for list/create/update/delete — `items` empty so the adopt-an-orphan path
            // resolves to "no existing route".
            'api.mailgun.net/v3/routes*' => Http::response([
                'message' => 'ok',
                'route' => ['id' => 'route-1'],
                'items' => [],
            ]),
            // Anything unmatched would otherwise leave the test making a real request.
            '*' => Http::response([]),
        ]);
    }

    /**
     * Names are randomized because this suite runs without DatabaseTransactions: a fixed name would
     * let an agent from an earlier run own `sofia@` and push every later one onto a suffixed address.
     */
    private function agent(?string $name = null): Agent
    {
        return Agent::factory()
            ->withAppId($this->kanvasApp->getId())
            ->withCompanyId($this->company->getId())
            ->create([
                'name' => $name ?? 'Sofia' . Str::random(8),
                'user_id' => $this->user->getId(),
            ]);
    }

    private function addressOf(Agent $agent): string
    {
        return Str::slug($agent->name) . '@' . self::DOMAIN;
    }

    private function receiverOf(Agent $agent): ReceiverWebhook
    {
        return ReceiverWebhook::getById((int) $agent->get(CustomFieldEnum::RECEIVER_ID->value), $agent->app);
    }
}
