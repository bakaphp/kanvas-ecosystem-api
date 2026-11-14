<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Mailgun;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Kanvas\Apps\Models\Apps;
use Kanvas\Communication\Connections\Models\Connection;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Mailgun\Enums\ConfigurationEnum;
use Kanvas\Connectors\Mailgun\Enums\CustomFieldEnum;
use Kanvas\Connectors\Mailgun\Handlers\MailgunHandler;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Regions\Models\Regions;
use Kanvas\Users\Models\Users;
use Kanvas\Workflow\Integrations\Models\IntegrationsCompany;
use Kanvas\Workflow\Models\Integrations;
use Tests\TestCase;

final class AdoptAgentMailboxesCommandTest extends TestCase
{
    use DatabaseTransactions;

    private const string DOMAIN = 'agents.kanvas.test';

    protected $connectionsToTransact = [null, 'workflow', 'intelligence', 'crm', 'social'];

    private Apps $kanvasApp;
    private Apps $originalApp;
    private Companies $company;
    private Users $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = auth()->user();
        $this->company = $this->user->getCurrentCompany();
        $this->originalApp = app(Apps::class);
        // An app of its own: the command walks every agent mailbox of the app it is given.
        $this->kanvasApp = $this->dedicatedApp();

        $this->kanvasApp->set(ConfigurationEnum::API_KEY->value, 'key-test');
        $this->kanvasApp->set(ConfigurationEnum::DOMAIN->value, self::DOMAIN);
        $this->kanvasApp->set(ConfigurationEnum::WEBHOOK_SIGNING_KEY->value, 'signing-key-test');

        Regions::create([
            'apps_id' => $this->kanvasApp->getId(),
            'companies_id' => $this->company->getId(),
            'users_id' => $this->user->getId(),
            'name' => 'Region ' . uniqid(),
            'is_default' => 1,
            'is_deleted' => 0,
        ]);

        Queue::fake();
        Http::fake(['*' => Http::response([])]);
        Integrations::firstOrCreate(
            ['handler' => MailgunHandler::class, 'apps_id' => 0],
            ['name' => 'Mailgun', 'config' => []]
        );
    }

    /**
     * The command binds the app it works on; the rest of the process still runs as the one it
     * started on.
     */
    protected function tearDown(): void
    {
        app()->instance(Apps::class, $this->originalApp);

        parent::tearDown();
    }

    public function testADryRunListsEachCompanyOnceWithItsMailboxes(): void
    {
        $this->agentWithMailbox();
        $this->agentWithMailbox();

        $this->artisan('kanvas:mailgun:adopt-agent-mailboxes', ['app_id' => $this->kanvasApp->getId(), '--dry-run' => true])
            ->expectsOutputToContain(sprintf(
                'app %d company %d (2 agent mailboxes)',
                $this->kanvasApp->getId(),
                $this->company->getId()
            ))
            ->expectsOutputToContain('0 of 1 companies with agent mailboxes reconciled.')
            ->assertSuccessful();

        $this->assertSame(0, $this->mailgunIntegrations());
    }

    public function testACompanyIsGivenTheIntegrationAndEveryMailboxItsConnectionInOnePass(): void
    {
        $first = $this->agentWithMailbox();
        $second = $this->agentWithMailbox();
        $deleted = $this->agentWithMailbox();
        $deleted->softDelete();

        $this->artisan('kanvas:mailgun:adopt-agent-mailboxes', ['app_id' => $this->kanvasApp->getId()])
            ->expectsOutputToContain('1 of 1 companies with agent mailboxes reconciled.')
            ->assertSuccessful();

        $this->assertSame(1, $this->mailgunIntegrations());
        $this->assertEqualsCanonicalizing(
            [$this->addressOf($first), $this->addressOf($second)],
            Connection::query()
                ->fromApp($this->kanvasApp->getId())
                ->fromCompany($this->company->getId())
                ->where('provider', MailgunHandler::slug())
                ->pluck('account_key')
                ->all()
        );
    }

    private function mailgunIntegrations(): int
    {
        return IntegrationsCompany::query()
            ->fromApp($this->kanvasApp->getId())
            ->fromCompany($this->company->getId())
            ->whereHas('integration', fn ($query) => $query->where('handler', MailgunHandler::class))
            ->count();
    }

    private function agentWithMailbox(): Agent
    {
        $agent = Agent::factory()
            ->withAppId($this->kanvasApp->getId())
            ->withCompanyId($this->company->getId())
            ->create([
                'name' => 'Sofia' . Str::random(8),
                'user_id' => $this->user->getId(),
            ]);
        $agent->set(CustomFieldEnum::MAILBOX_ADDRESS->value, $this->addressOf($agent));

        return $agent;
    }

    private function addressOf(Agent $agent): string
    {
        return Str::slug($agent->name) . '@' . self::DOMAIN;
    }

    private function dedicatedApp(): Apps
    {
        $uniqueId = uniqid('mailgun', true);

        $app = new Apps();
        $app->name = 'Mailgun Adopt Test ' . $uniqueId;
        $app->url = 'https://' . $uniqueId . '.example.com';
        $app->domain = $uniqueId . '.example.com';
        $app->description = 'Agent mailbox adoption test app';
        $app->is_actived = 1;
        $app->ecosystem_auth = 0;
        $app->payments_active = 0;
        $app->is_public = 0;
        $app->domain_based = 0;
        $app->saveOrFail();

        return $app;
    }
}
