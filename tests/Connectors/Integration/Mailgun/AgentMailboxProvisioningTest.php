<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Mailgun;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Kanvas\Apps\Models\Apps;
use Kanvas\Communication\Connections\Jobs\EnsureCommunicationSetupJob;
use Kanvas\Communication\Connections\Models\Connection;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Mailgun\Actions\DisconnectAgentMailboxAction;
use Kanvas\Connectors\Mailgun\Actions\ProvisionAgentMailboxAction;
use Kanvas\Connectors\Mailgun\Enums\ConfigurationEnum;
use Kanvas\Connectors\Mailgun\Enums\CustomFieldEnum;
use Kanvas\Connectors\Mailgun\Enums\MailboxAccessEnum;
use Kanvas\Connectors\Mailgun\Handlers\MailgunHandler;
use Kanvas\Connectors\Mailgun\Services\AgentMailboxService;
use Kanvas\Enums\AppEnums;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Regions\Models\Regions;
use Kanvas\Users\Models\Users;
use Kanvas\Workflow\Integrations\Models\IntegrationsCompany;
use Kanvas\Workflow\Models\Integrations;
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

        $this->user = auth()->user();
        $this->company = $this->user->getCurrentCompany();
        // Own app row, not app(Apps::class): Mailgun config lives on the app, settings survive a
        // rollback, and paratest runs test CLASSES concurrently against one database — MailgunHandlerTest
        // deletes MAILGUN_API_KEY in its tearDown and AttachEmailAttachmentsToLeadActionTest blanks the
        // app signing key, either of which lands mid-test here. An app of our own can't be raced.
        $this->kanvasApp = $this->dedicatedApp();

        $this->kanvasApp->set(ConfigurationEnum::API_KEY->value, 'key-test');
        $this->kanvasApp->set(ConfigurationEnum::DOMAIN->value, self::DOMAIN);
        $this->kanvasApp->set(ConfigurationEnum::WEBHOOK_SIGNING_KEY->value, 'signing-key-test');
        // Custom fields outlive a rollback and every agent here shares one user — a contact_email
        // left by an earlier test would decide this one's outcome.
        $this->user->del('contact_email');

        // The integration a first mailbox gives the company is filed under its region in this app.
        Regions::create([
            'apps_id' => $this->kanvasApp->getId(),
            'companies_id' => $this->company->getId(),
            'users_id' => $this->user->getId(),
            'name' => 'Region ' . uniqid(),
            'is_default' => 1,
            'is_deleted' => 0,
        ]);

        // Registering the route with Mailgun is the setup job's work, covered in MailgunProviderTest.
        Queue::fake();
        Integrations::firstOrCreate(
            ['handler' => MailgunHandler::class, 'apps_id' => 0],
            ['name' => 'Mailgun', 'config' => []]
        );
    }

    private function dedicatedApp(): Apps
    {
        $uniqueId = uniqid('mailgun', true);

        $app = new Apps();
        $app->name = 'Mailgun Mailbox Test ' . $uniqueId;
        $app->url = 'https://' . $uniqueId . '.example.com';
        $app->domain = $uniqueId . '.example.com';
        $app->description = 'Agent mailbox provisioning test app';
        $app->is_actived = 1;
        $app->ecosystem_auth = 0;
        $app->payments_active = 0;
        $app->is_public = 0;
        $app->domain_based = 0;
        $app->saveOrFail();

        return $app;
    }

    public function testProvisioningGivesTheAgentAnAddressOnAConnectionOfTheCompanysMailgun(): void
    {
        $this->fakeMailgun();
        $agent = $this->agent();

        $mailbox = new ProvisionAgentMailboxAction($agent)->execute();

        $this->assertSame($this->addressOf($agent), $mailbox['address']);
        $this->assertSame(MailboxAccessEnum::RESTRICTED->value, $mailbox['access']);

        $connection = Connection::query()
            ->fromApp($this->kanvasApp)
            ->fromCompany($this->company)
            ->where('provider', MailgunHandler::slug())
            ->where('account_key', $mailbox['address'])
            ->sole();

        $this->assertTrue($connection->agent()->is($agent));
        $this->assertTrue($connection->personalOwner()->is($agent));
        Queue::assertPushed(EnsureCommunicationSetupJob::class);
        // The route is the connection's to register, never the mailbox's own.
        Http::assertNotSent(fn ($request): bool => str_contains($request->url(), '/v3/routes'));
    }

    /**
     * Mailgun set up once for the app is enough: the first mailbox gives the company the
     * integration its connection hangs off.
     */
    public function testACompanyThatNeverIntegratedMailgunGetsTheIntegrationWithItsFirstMailbox(): void
    {
        $this->fakeMailgun();

        new ProvisionAgentMailboxAction($this->agent())->execute();
        new ProvisionAgentMailboxAction($this->agent())->execute();

        $this->assertSame(1, IntegrationsCompany::query()
            ->fromApp($this->kanvasApp)
            ->fromCompany($this->company)
            ->whereHas('integration', fn ($query) => $query->where('handler', MailgunHandler::class))
            ->count());
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

    public function testReprovisioningKeepsTheAddressAndItsOneConnection(): void
    {
        $this->fakeMailgun();
        $agent = $this->agent();

        $first = new ProvisionAgentMailboxAction($agent)->execute();
        $second = new ProvisionAgentMailboxAction($agent->refresh(), MailboxAccessEnum::OPEN)->execute();

        $this->assertSame($first['address'], $second['address']);
        $this->assertSame(MailboxAccessEnum::OPEN->value, $second['access']);
        $this->assertSame(1, Connection::query()
            ->fromApp($this->kanvasApp)
            ->where('account_key', $first['address'])
            ->count());
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
        $agent = $this->agentInAnUnconfiguredCompany();
        $this->kanvasApp->del(ConfigurationEnum::DOMAIN->value);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessageMatches('/Mailgun domain/');

        new ProvisionAgentMailboxAction($agent)->execute();
    }

    public function testProvisioningRefusesWhenTheWebhookSigningKeyIsMissing(): void
    {
        $this->fakeMailgun();
        $agent = $this->agentInAnUnconfiguredCompany();
        $this->kanvasApp->del(ConfigurationEnum::WEBHOOK_SIGNING_KEY->value);

        // A mailbox whose inbound is rejected 401 forever is worse than no mailbox.
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessageMatches('/signing key/');

        new ProvisionAgentMailboxAction($agent)->execute();
    }

    /**
     * A customer line is the company's own address, handed to an agent when its old responder
     * rule is retired; it is never provisioned onto an address named after the agent.
     */
    public function testACustomerLineIsNeverProvisionedOnAnAgent(): void
    {
        $this->fakeMailgun();
        $agent = $this->agent();

        try {
            new ProvisionAgentMailboxAction($agent, MailboxAccessEnum::CUSTOMER_LINE)->execute();
            $this->fail('A customer line was provisioned on an agent.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('customer line', $exception->getMessage());
        }

        $this->assertNull(new AgentMailboxService()->addressFor($agent->refresh()));
        $this->artisan('kanvas:mailgun:provision-agent-mailbox', ['--agent' => $agent->getId(), '--access' => 'customer_line'])
            ->expectsOutputToContain('customer line')
            ->assertFailed();
    }

    public function testAnAgentAnsweringTheCompanysAddressGetsAMailboxOfItsOwnInstead(): void
    {
        $this->fakeMailgun();
        $agent = $this->agent();
        $companyAddress = 'sales@' . self::DOMAIN;
        $agent->set(CustomFieldEnum::MAILBOX_ADDRESS->value, $companyAddress);
        $agent->set(CustomFieldEnum::MAILBOX_ACCESS->value, MailboxAccessEnum::CUSTOMER_LINE->value);

        $mailbox = new ProvisionAgentMailboxAction($agent->refresh())->execute();

        $this->assertNotSame($companyAddress, $mailbox['address']);
        $this->assertSame($this->addressOf($agent->refresh()), $mailbox['address']);
        $this->assertSame(MailboxAccessEnum::RESTRICTED->value, $mailbox['access']);
    }

    public function testDisconnectClearsTheAddressAndRetiresItsConnection(): void
    {
        $this->fakeMailgun();
        $agent = $this->agent();
        $provisioned = new ProvisionAgentMailboxAction($agent)->execute();

        new DisconnectAgentMailboxAction($agent->refresh())->execute();

        $agent = $agent->refresh();

        $this->assertNull(new AgentMailboxService()->addressFor($agent));
        $this->assertNull($agent->user->get('contact_email'));
        $this->assertFalse(Connection::query()
            ->fromApp($this->kanvasApp)
            ->live()
            ->where('account_key', $provisioned['address'])
            ->exists());
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

    /**
     * Query + disconnect only. Provisioning through GraphQL would need the Mailgun API key on the
     * REAL app (the resolver reads app(Apps::class), and this request has to be pinned to it) —
     * which MailgunHandlerTest deletes from another paratest process. The provision resolver is a
     * three-line delegation to ProvisionAgentMailboxAction, covered by the tests above.
     */
    public function testTheGraphQLSurfaceIsWired(): void
    {
        $realApp = app(Apps::class);
        $address = 'sofia-' . strtolower(Str::random(6)) . '@' . self::DOMAIN;

        $agent = Agent::factory()
            ->withAppId($realApp->getId())
            ->withCompanyId($this->company->getId())
            ->create(['name' => 'Sofia' . Str::random(8), 'user_id' => $this->user->getId()]);
        $agent->set(CustomFieldEnum::MAILBOX_ADDRESS->value, $address);

        // Pin the request to the app the agent lives under, or the resolver's app(Apps::class) is
        // resolved from the request host and getByIdFromCompanyApp can't find the agent.
        $headers = [
            AppEnums::KANVAS_APP_KEY_HEADER->getValue() => $realApp->keys()->first()->client_secret_id,
        ];

        $this->graphQL('
            query ($id: ID!) { agentMailbox(agent_id: $id) { address access } }
        ', ['id' => $agent->getId()], [], $headers)
            ->assertSuccessful()
            ->assertJsonPath('data.agentMailbox.address', $address)
            ->assertJsonPath('data.agentMailbox.access', 'RESTRICTED');

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
     * The platform already sends its own mail through one Mailgun domain, so an install that never
     * copied that domain onto a company had agent mailboxes refuse to provision — while the
     * integration reported itself connected, because only the signing key is required to set up.
     */
    public function testThePlatformMailgunDomainIsUsedWhenNoTenantSetsOne(): void
    {
        config([
            'services.mailgun.domain' => 'platform.kanvas.test',
            'services.mailgun.secret' => 'key-platform',
        ]);

        $this->kanvasApp->del(ConfigurationEnum::DOMAIN->value);
        $this->kanvasApp->del(ConfigurationEnum::API_KEY->value);

        // Its own company, per the note on the helper: siblings write MAILGUN_DOMAIN on the shared one,
        // and company config is read first — on the shared company this asserts their value, not ours.
        $agent = $this->agentInAnUnconfiguredCompany();

        $this->assertSame('platform.kanvas.test', new AgentMailboxService()->domainFor($agent));
        $this->assertTrue(new AgentMailboxService()->isConfiguredFor($agent));
    }

    /**
     * The fallback is last, not first: a tenant given its own sending domain must take its agents
     * with it rather than keep handing out addresses on the platform's.
     */
    public function testATenantDomainStillBeatsThePlatformOne(): void
    {
        config(['services.mailgun.domain' => 'platform.kanvas.test']);

        $agent = $this->agentInAnUnconfiguredCompany();

        $this->assertSame(self::DOMAIN, new AgentMailboxService()->domainFor($agent), 'the app setting outranks config');

        $agent->company->set(ConfigurationEnum::DOMAIN->value, 'tenant.kanvas.test');
        $this->assertSame('tenant.kanvas.test', new AgentMailboxService()->domainFor($agent), 'the company outranks both');
    }

    /**
     * Names are randomized because this suite runs without DatabaseTransactions: a fixed name would
     * let an agent from an earlier run own `sofia@` and push every later one onto a suffixed address.
     */
    private function agent(?string $name = null, ?Companies $company = null): Agent
    {
        return Agent::factory()
            ->withAppId($this->kanvasApp->getId())
            ->withCompanyId(($company ?? $this->company)->getId())
            ->create([
                'name' => $name ?? 'Sofia' . Str::random(8),
                'user_id' => $this->user->getId(),
            ]);
    }

    /**
     * Company config is read before app config, and the shared company is written by sibling test
     * classes running in other paratest processes — a company of our own is the only way to assert
     * "nothing configured" and mean it.
     */
    private function agentInAnUnconfiguredCompany(): Agent
    {
        return $this->agent(company: Companies::factory()->create());
    }

    private function addressOf(Agent $agent): string
    {
        return Str::slug($agent->name) . '@' . self::DOMAIN;
    }
}
