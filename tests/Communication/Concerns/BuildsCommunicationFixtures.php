<?php

declare(strict_types=1);

namespace Tests\Communication\Concerns;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Support\Facades\Queue;
use Kanvas\Apps\Actions\CreateAppsAction;
use Kanvas\Apps\DataTransferObject\AppInput;
use Kanvas\Apps\Models\Apps;
use Kanvas\Communication\Communication;
use Kanvas\Communication\Connections\Actions\EnsureCommunicationSetupAction;
use Kanvas\Communication\Connections\DataTransferObject\SetupReport;
use Kanvas\Communication\Connections\Enums\SetupStatusEnum;
use Kanvas\Communication\Connections\Models\ChannelDefault;
use Kanvas\Communication\Connections\Models\Connection;
use Kanvas\Communication\Connections\Models\InboundClaim;
use Kanvas\Communication\Connections\Services\SharedRegistry;
use Kanvas\Communication\Setup\Enums\DefaultAgentEnum;
use Kanvas\Communication\Setup\Jobs\SetupPeopleChannelsJob;
use Kanvas\Communication\Support\ProviderRegistry;
use Kanvas\Companies\Models\Companies;
use Kanvas\Guild\Customers\Enums\ContactTypeEnum;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Regions\Models\Regions;
use Kanvas\Social\Channels\Actions\CreateChannelAction;
use Kanvas\Social\Channels\DataTransferObject\Channel as ChannelDto;
use Kanvas\Social\Channels\Models\Channel;
use Kanvas\Social\Messages\Actions\CreateMessageAction;
use Kanvas\Social\Messages\DataTransferObject\MessageInput;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Social\MessagesTypes\Models\MessageType;
use Kanvas\SystemModules\Models\SystemModules;
use Kanvas\Workflow\Enums\StatusEnum;
use Kanvas\Workflow\Integrations\Actions\CreateIntegrationCompanyAction;
use Kanvas\Workflow\Integrations\Actions\UpdateIntegrationCompanyAction;
use Kanvas\Workflow\Integrations\DataTransferObject\IntegrationsCompany as IntegrationsCompanyData;
use Kanvas\Workflow\Integrations\Models\IntegrationsCompany;
use Kanvas\Workflow\Integrations\Models\Status;
use Kanvas\Workflow\Models\Integrations;
use Tests\Communication\Fixtures\FakeInbound;
use Tests\Communication\Fixtures\FakeProvider;
use Tests\Communication\Fixtures\FakeProvisioner;
use Tests\Communication\Fixtures\FakeTransport;

trait BuildsCommunicationFixtures
{
    use KanvasJobsTrait;

    protected function currentApp(): Apps
    {
        return app(Apps::class);
    }

    protected function currentCompany(): Companies
    {
        return auth()->user()->getCurrentCompany();
    }

    protected function otherCompany(): Companies
    {
        return Companies::factory()->create();
    }

    /**
     * Creating an app leaves it, and its permission scope, as the current one; the test, and its
     * tearDown, still run as the app they started on.
     */
    protected function otherApp(): Apps
    {
        $app = $this->currentApp();

        try {
            return new CreateAppsAction(
                AppInput::from([
                    'url' => 'communication.test',
                    'is_actived' => '1',
                    'ecosystem_auth' => '1',
                    'payments_active' => '1',
                    'is_public' => '1',
                    'domain_based' => '1',
                    'name' => 'Communication app ' . uniqid(),
                    'description' => 'Second app for another tenant',
                    'domain' => 'communication.test',
                ]),
                auth()->user()
            )->execute();
        } finally {
            $this->overwriteAppService($app);
        }
    }

    protected function communication(): Communication
    {
        return Communication::for($this->currentApp(), $this->currentCompany());
    }

    /**
     * Swaps the registered providers for one whose transport records calls instead of making them.
     */
    protected function useFakeProvider(): FakeTransport
    {
        config(['communication.providers' => [FakeProvider::class]]);

        $transport = new FakeTransport();
        app()->instance(FakeTransport::class, $transport);

        return $transport;
    }

    /**
     * The fake vendor's webhook side, the one instance the communication webhook resolves.
     */
    protected function useFakeInbound(): FakeInbound
    {
        $this->useFakeProvider();

        $inbound = new FakeInbound();
        app()->instance(FakeInbound::class, $inbound);

        return $inbound;
    }

    /**
     * A ready, switched-on connection that is the company's default for SMS.
     */
    protected function defaultSmsConnection(
        string $provider = 'fake',
        string $number = '+18095550100',
        array $attributes = []
    ): Connection {
        $connection = $this->connection($provider, $number, $attributes);

        ChannelDefault::updateOrCreate(
            [
                'apps_id' => $connection->apps_id,
                'companies_id' => $connection->companies_id,
                'channel' => 'sms',
            ],
            ['connection_id' => $connection->getId()]
        );

        return $connection;
    }

    protected function connection(string $provider, string $number, array $attributes = []): Connection
    {
        $integration = Integrations::create([
            'apps_id' => 0,
            'name' => uniqid('communication_'),
            'handler' => new ProviderRegistry()->all()[$provider] ?? 'none',
        ]);

        $integrationCompany = IntegrationsCompany::create([
            'apps_id' => $this->currentApp()->getId(),
            'companies_id' => $this->currentCompany()->getId(),
            'integrations_id' => $integration->getId(),
            'region_id' => $this->region($this->currentCompany())->getId(),
            'status_id' => Status::where('slug', StatusEnum::ACTIVE->value)->where('apps_id', 0)->firstOrFail()->getId(),
        ]);

        return Connection::create($attributes + [
            'apps_id' => $this->currentApp()->getId(),
            'companies_id' => $this->currentCompany()->getId(),
            'integrations_company_id' => $integrationCompany->getId(),
            'provider' => $provider,
            'account_key' => $number,
            'setup_status' => SetupStatusEnum::READY,
            'enabled' => true,
            'activation_version' => 1,
        ]);
    }

    /**
     * The fake provider with a fake vendor behind it, for driving provisioning end to end.
     */
    protected function useFakeProvisioner(): FakeProvisioner
    {
        $this->useFakeProvider();

        $provisioner = new FakeProvisioner(new SharedRegistry());
        app()->instance(FakeProvisioner::class, $provisioner);
        $this->globalAgentTemplates();
        Queue::fake([SetupPeopleChannelsJob::class]);

        return $provisioner;
    }

    /**
     * The app's global agents a company's setup copies; without them setup is blocked.
     */
    /**
     * @return array<string, Agent> by name
     */
    protected function globalAgentTemplates(): array
    {
        $templates = [];

        foreach (DefaultAgentEnum::cases() as $default) {
            $templates[$default->value] = Agent::factory()
                ->withAppId($this->currentApp()->getId())
                ->withCompanyId(0)
                ->create(['name' => $default->value]);
        }

        return $templates;
    }

    /**
     * Completes a messaging integration the way every entry path does — through the create action,
     * which is where setup is triggered.
     *
     * @param list<string|array<string, mixed>> $numbers
     */
    protected function integrateMessaging(array $numbers, ?Companies $company = null): IntegrationsCompany
    {
        Queue::fake();

        $integration = Integrations::create([
            'apps_id' => 0,
            'name' => uniqid('messaging_'),
            'handler' => FakeProvider::class,
        ]);

        return new CreateIntegrationCompanyAction(
            $this->integrationData($integration, $numbers, $company),
            auth()->user(),
            $this->activeStatus()
        )->execute();
    }

    /**
     * @param list<string|array<string, mixed>> $numbers
     */
    protected function resaveIntegration(IntegrationsCompany $integrationCompany, array $numbers): IntegrationsCompany
    {
        return new UpdateIntegrationCompanyAction(
            $integrationCompany,
            $this->integrationData($integrationCompany->integration, $numbers, $integrationCompany->company),
            $this->activeStatus()
        )->execute();
    }

    protected function runSetup(IntegrationsCompany $integrationCompany, bool $respectBackoff = false): SetupReport
    {
        return new EnsureCommunicationSetupAction(
            $this->currentApp(),
            $integrationCompany->fresh(),
            respectBackoff: $respectBackoff
        )->execute();
    }

    /**
     * @param list<string|array<string, mixed>> $numbers
     */
    private function integrationData(Integrations $integration, array $numbers, ?Companies $company): IntegrationsCompanyData
    {
        $selection = array_map(
            fn (string|array $number): array => is_string($number) ? ['phone_number' => $number] : $number,
            $numbers
        );

        return new IntegrationsCompanyData(
            app: $this->currentApp(),
            integration: $integration,
            company: $company ?? $this->currentCompany(),
            region: $this->region($company ?? $this->currentCompany()),
            config: [],
            persistedConfig: $selection === [] ? [] : ['phone_numbers' => $selection]
        );
    }

    /**
     * A real one: the handler hooks are skipped for a row whose region does not exist.
     */
    private function region(Companies $company): Regions
    {
        return Regions::getDefault($company, $this->currentApp()) ?? Regions::create([
            'apps_id' => $this->currentApp()->getId(),
            'companies_id' => $company->getId(),
            'users_id' => 0,
            'name' => 'Region ' . uniqid(),
            'is_default' => 1,
            'is_deleted' => 0,
        ]);
    }

    private function activeStatus(): Status
    {
        return Status::where('slug', StatusEnum::ACTIVE->value)->where('apps_id', 0)->firstOrFail();
    }

    /**
     * The inbound owner a provisioned connection holds; connections built directly in a test have
     * none until they claim one.
     */
    protected function claim(Connection $connection, ?int $companyId = null): InboundClaim
    {
        return InboundClaim::query()->create([
            'provider' => $connection->provider,
            'inbound_identity' => $connection->account_key,
            'connection_id' => $connection->getId(),
            'apps_id' => $connection->apps_id,
            'companies_id' => $companyId ?? $connection->companies_id,
            'claimed_at' => now(),
        ]);
    }

    protected function personWithCellphone(string $number = '+18095550199'): People
    {
        $people = People::factory()
            ->withAppId($this->currentApp()->getId())
            ->withCompanyId($this->currentCompany()->getId())
            ->create();

        $people->contacts()->create([
            'contacts_types_id' => ContactTypeEnum::CELLPHONE->value,
            'value' => $number,
            'weight' => 0,
        ]);

        return $people;
    }

    protected function leadOf(People $person): Lead
    {
        return Lead::factory()
            ->withAppAndCompany($this->currentApp()->getId(), $this->currentCompany()->getId())
            ->withPeopleId($person->getId())
            ->create();
    }

    /**
     * The Social channel a provider's inbound files a conversation in, named the way that
     * provider's auto-reply rule recognises it.
     */
    protected function conversationChannel(Lead $lead, string $slugPrefix = 'fake-'): Channel
    {
        SystemModules::firstOrCreate(
            ['model_name' => Lead::class],
            ['name' => 'Leads', 'slug' => 'leads', 'description' => 'Leads system module']
        );

        return new CreateChannelAction(ChannelDto::from([
            'apps' => $this->currentApp(),
            'companies' => $this->currentCompany(),
            'users' => $lead->user,
            'entity_id' => $lead->getId(),
            'entity_namespace' => Lead::class,
            'name' => 'Conversation ' . $lead->getId(),
            'slug' => $slugPrefix . $lead->getId(),
        ]))->execute();
    }

    protected function fileInConversation(
        Channel $channel,
        Lead $lead,
        string $verb,
        array $payload
    ): Message {
        $create = new CreateMessageAction(new MessageInput(
            app: $this->currentApp(),
            company: $this->currentCompany(),
            user: auth()->user(),
            type: MessageType::firstOrCreate(
                ['apps_id' => $this->currentApp()->getId(), 'languages_id' => 1, 'verb' => $verb],
                ['name' => ucfirst($verb)]
            ),
            message: $payload,
            is_public: 1,
        ));
        $create->runWorkflow = false;
        $message = $create->execute();

        $message->addEntity($lead);
        $channel->addMessage($message);

        return $message;
    }
}
