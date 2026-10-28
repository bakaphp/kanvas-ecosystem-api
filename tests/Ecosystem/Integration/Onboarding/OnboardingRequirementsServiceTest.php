<?php

declare(strict_types=1);

namespace Tests\Ecosystem\Integration\Onboarding;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Companies\Onboarding\Models\CompanyOnboarding;
use Kanvas\Companies\Onboarding\Models\OnboardingSection;
use Kanvas\Companies\Onboarding\Services\OnboardingStateService;
use Kanvas\Connectors\Mailgun\Enums\CustomFieldEnum as MailgunCustomFieldEnum;
use Kanvas\Connectors\Slack\Enums\ConfigurationEnum as SlackConfigurationEnum;
use Kanvas\Connectors\Slack\Services\SlackListenerReceiverService;
use Kanvas\Connectors\Slack\Webhooks\ProcessSlackListenerWebhookJob;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Regions\Models\Regions;
use Kanvas\Workflow\Enums\StatusEnum;
use Kanvas\Workflow\Integrations\Models\IntegrationsCompany;
use Kanvas\Workflow\Integrations\Models\Status;
use Kanvas\Workflow\Models\Integrations;
use Kanvas\Workflow\Models\WorkflowAction;
use Tests\GraphQL\Inventory\Traits\InventoryCases;
use Tests\TestCase;

final class OnboardingRequirementsServiceTest extends TestCase
{
    use DatabaseTransactions;
    use InventoryCases;

    protected $connectionsToTransact = [null, 'ecosystem', 'workflow'];

    private string $prefix;
    private Apps $kanvasApp;
    private Companies $company;
    private Integrations $integration;
    private CompanyOnboarding $row;

    protected function setUp(): void
    {
        parent::setUp();

        $this->prefix = 'r' . substr(uniqid(), -6) . '_';
        $this->kanvasApp = app(Apps::class);
        $this->company = auth()->user()->getCurrentCompany();
        $this->integration = Integrations::query()->create([
            'apps_id' => $this->kanvasApp->getId(),
            'name' => $this->prefix . 'books',
            'handler' => 'none',
            'type' => 'key',
        ]);

        $this->section('ap', 1, [
            ['slug' => 'ap', 'name' => 'AP', 'description' => 'x', 'source_agent' => 'AP', 'agent_type' => 'AP', 'requirements' => ['bills_inbox', 'accounting_software']],
        ], [
            [
                'slug' => 'bills_inbox',
                'label' => 'Where your bills arrive',
                'required' => true,
                'shared' => false,
                'options' => [['label' => 'Kanvas email', 'mode' => 'kanvas_provided', 'address_from' => 'agent_mailbox']],
            ],
            [
                'slug' => 'accounting_software',
                'label' => 'Your accounting software',
                'required' => true,
                'shared' => true,
                'allow_not_used' => true,
                'options' => [['label' => 'Books', 'integration_id' => $this->integration->getId(), 'mode' => 'connect']],
            ],
            [
                'slug' => 'workspace_channel',
                'label' => 'Where your internal assistants work',
                'required' => false,
                'shared' => true,
                'options' => [['label' => 'Slack', 'connector' => 'slack_workspace', 'mode' => 'connect']],
            ],
        ]);
        $this->section('ar', 2, [
            ['slug' => 'ar', 'name' => 'AR', 'description' => 'x', 'source_agent' => 'AR', 'agent_type' => 'AR', 'requirements' => ['accounting_software', 'workspace_channel']],
        ]);

        $this->row = new OnboardingStateService()->forCompany($this->kanvasApp, $this->company, auth()->user());
        $this->row->dataSet('areas_selected', [['slug' => $this->prefix . 'ap'], ['slug' => $this->prefix . 'ar']]);
    }

    public function testIntegrationOptionReadsActiveIntegrationCompanyRow(): void
    {
        $this->assertFalse($this->option('accounting_software', 'Books')['connected']);

        $this->integrationRow();

        $this->assertTrue($this->option('accounting_software', 'Books')['connected']);
    }

    public function testInactiveOrFailedRowIsNotConnected(): void
    {
        $row = $this->integrationRow();
        $row->isActive(false);
        $this->assertFalse($this->option('accounting_software', 'Books')['connected']);

        $row->isActive(true);
        $row->setStatus(Status::getDefaultStatusByName(StatusEnum::FAILED->value));
        $this->assertFalse($this->option('accounting_software', 'Books')['connected']);
    }

    public function testSharedSlotAppearsOnceWithSatisfiedBy(): void
    {
        $requirements = $this->requirements();

        $this->assertSame(['bills_inbox', 'accounting_software', 'workspace_channel'], array_keys($requirements));
        $this->assertSame('ap', $requirements['accounting_software']['satisfied_by']);
        $this->assertSame('ar', $requirements['workspace_channel']['satisfied_by']);
        $this->assertNull($requirements['bills_inbox']['satisfied_by']);
    }

    public function testPendingConnectPromotedToConnectedWhenRowAppears(): void
    {
        $this->row->dataSet('requirements.accounting_software', [
            'status' => 'pending_connect',
            'mode' => 'connect',
            'integration_id' => $this->integration->getId(),
        ]);

        $this->assertSame('PENDING_CONNECT', $this->requirements()['accounting_software']['status']);

        $this->integrationRow();

        $this->assertSame('CONNECTED', $this->requirements()['accounting_software']['status']);
    }

    public function testSlackWorkspaceConnectorUsesListenerStatus(): void
    {
        $this->assertFalse($this->option('workspace_channel', 'Slack')['connected']);

        WorkflowAction::firstOrCreate(
            ['model_name' => ProcessSlackListenerWebhookJob::class],
            ['name' => 'ProcessSlackListenerWebhookJob'],
        );
        $receiver = new SlackListenerReceiverService()->forCompany($this->kanvasApp, $this->company, auth()->user());
        $receiver->configuration = [SlackConfigurationEnum::BOT_TOKEN->value => 'xoxb-' . $this->prefix];
        $receiver->saveOrFail();

        $this->assertTrue($this->option('workspace_channel', 'Slack')['connected']);
    }

    public function testInboundAddressComesFromTheProvisionedAgentMailbox(): void
    {
        $this->assertNull($this->requirements()['bills_inbox']['inbound_address']);

        $agent = Agent::factory()
            ->withAppId($this->kanvasApp->getId())
            ->withCompanyId($this->company->getId())
            ->create(['name' => 'AP ' . $this->prefix]);
        $this->row->dataSet('provisioning.ap', ['status' => 'ready', 'agent_id' => $agent->getId(), 'error' => null]);

        $this->assertNull($this->requirements()['bills_inbox']['inbound_address']);

        $agent->set(MailgunCustomFieldEnum::MAILBOX_ADDRESS->value, 'ap-' . $this->prefix . '@example.test');

        $requirements = $this->requirements();
        $this->assertSame('ap-' . $this->prefix . '@example.test', $requirements['bills_inbox']['inbound_address']);
        $this->assertNull($requirements['accounting_software']['inbound_address']);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function requirements(): array
    {
        $state = new OnboardingStateService()->state($this->row->refresh());

        return array_column($state->requirements, null, 'slug');
    }

    /**
     * @return array<string, mixed>
     */
    private function option(string $requirement, string $label): array
    {
        foreach ($this->requirements()[$requirement]['options'] as $option) {
            if ($option['label'] === $label) {
                return $option;
            }
        }

        $this->fail("Option {$label} not found on {$requirement}");
    }

    private function integrationRow(): IntegrationsCompany
    {
        $region = Regions::query()->fromApp($this->kanvasApp)->fromCompany($this->company)->first()
            ?? $this->createDefaultRegion($this->company, $this->kanvasApp, auth()->user());

        return IntegrationsCompany::query()->create([
            'apps_id' => $this->kanvasApp->getId(),
            'companies_id' => $this->company->getId(),
            'integrations_id' => $this->integration->getId(),
            'region_id' => $region->getId(),
            'status_id' => Status::getDefaultStatusByName(StatusEnum::ACTIVE->value)->getId(),
            'is_active' => true,
        ]);
    }

    /**
     * @param list<array<string, mixed>> $agents
     * @param list<array<string, mixed>> $requirements
     */
    private function section(string $base, int $order, array $agents, array $requirements = []): OnboardingSection
    {
        return OnboardingSection::query()->create([
            'apps_id' => $this->kanvasApp->getId(),
            'slug' => $this->prefix . $base,
            'label' => 'Label ' . $base,
            'title' => 'Title ' . $base,
            'description' => 'Description',
            'is_available' => true,
            'display_order' => $order,
            'is_active' => true,
            'config' => ['agents' => $agents, 'modules' => [], 'requirements' => $requirements, 'capabilities' => [], 'setup_steps' => []],
        ]);
    }
}
