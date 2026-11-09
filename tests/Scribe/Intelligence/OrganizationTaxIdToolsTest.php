<?php

declare(strict_types=1);

namespace Tests\Scribe\Intelligence;

use Kanvas\Approvals\Actions\ApproveAction;
use Kanvas\Approvals\Enums\ApprovalOriginEnum;
use Kanvas\Approvals\Services\ApprovalOriginService;
use Kanvas\Guild\Organizations\Enums\TaxIdTypeEnum;
use Kanvas\Guild\Organizations\Models\Organization;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Neuron\Accounting\AccountsPayableAgent;
use Kanvas\Intelligence\Agents\Neuron\Accounting\AccountsReceivableAgent;
use Kanvas\Intelligence\Agents\Neuron\SystemUserAgent;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\CreateOrganizationTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\UpdateOrganizationTool;
use Kanvas\Scribe\Invoices\Actions\SubmitInvoiceForApprovalAction;
use Kanvas\Scribe\Invoices\Enums\InvoiceDocumentStatusEnum;
use Kanvas\Scribe\Invoices\Models\Invoice;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use Tests\Scribe\ElectronicInvoicing\EcfTestCase;
use Tests\Stubs\Scribe\FakeEcfProvider;
use Throwable;

/**
 * create_organization / update_organization with a tax id, on a Dominican company whose e-CF provider is
 * the fake one (its `$taxpayers` is DGII).
 */
final class OrganizationTaxIdToolsTest extends EcfTestCase
{
    private const string LEGAL_NAME = 'CLIENTE RNC SRL';

    protected function afterScribeSetUp(): void
    {
        parent::afterScribeSetUp();

        $this->company->country_code = 'DO';
        $this->company->saveOrFail();
        FakeEcfProvider::$taxpayers = [self::RNC => self::LEGAL_NAME];
    }

    public function test_a_verified_rnc_is_saved_with_the_dgii_legal_name(): void
    {
        $result = $this->createTool()->__invoke(name: 'Cliente Verificado ' . uniqid(), tax_id: '131-79432-1');

        $this->assertTrue($result['created']);
        $this->assertSame(self::RNC, $result['tax_id']);
        $this->assertSame('do_rnc', $result['tax_id_type']);
        $this->assertSame('RNC', $result['tax_id_label']);
        $this->assertSame('DO', $result['country']);
        $this->assertSame('verified', $result['tax_id_verification']);
        $this->assertSame(self::LEGAL_NAME, $result['dgii_legal_name']);
    }

    public function test_a_badly_formed_dominican_id_is_refused_and_nothing_is_created(): void
    {
        $name = 'Cliente Malo ' . uniqid();
        $result = $this->createTool()->__invoke(name: $name, tax_id: '13179432');

        $this->assertFalse($result['success']);
        $this->assertSame('invalid_args', $result['outcome']);
        $this->assertStringContainsString('RNC has 9 digits and a cédula 11', $result['note']);
        $this->assertSame(0, $this->organizationsNamed($name));
    }

    public function test_an_rnc_dgii_does_not_know_is_refused(): void
    {
        $name = 'Cliente Fantasma ' . uniqid();
        $result = $this->createTool()->__invoke(name: $name, tax_id: '101000001');

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('DGII has no taxpayer registered under RNC 101000001', $result['error']);
        $this->assertStringContainsString('check_rnc', $result['note']);
        $this->assertSame(0, $this->organizationsNamed($name));
    }

    public function test_a_foreign_vendor_needs_its_type_and_skips_dgii(): void
    {
        $result = $this->createTool()->__invoke(
            name: 'US Vendor ' . uniqid(),
            tax_id: '12-3456789',
            tax_id_type: 'us_ein',
        );

        $this->assertTrue($result['created']);
        $this->assertSame('123456789', $result['tax_id']);
        $this->assertSame('us_ein', $result['tax_id_type']);
        $this->assertSame('not_applicable', $result['tax_id_verification']);
        $this->assertArrayNotHasKey('dgii_legal_name', $result);
    }

    public function test_an_explicit_type_that_does_not_fit_is_refused(): void
    {
        $result = $this->createTool()->__invoke(name: 'Mal Tipo ' . uniqid(), tax_id: '00112345678', tax_id_type: 'do_rnc');

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('is not a valid RNC', $result['error']);
    }

    public function test_an_unknown_type_code_lists_the_allowed_ones(): void
    {
        $result = $this->createTool()->__invoke(name: 'Tipo Raro ' . uniqid(), tax_id: '131794321', tax_id_type: 'vat');

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('do_rnc, do_cedula', $result['error']);
    }

    public function test_update_saves_a_verified_tax_id_and_leaves_it_alone_when_not_given(): void
    {
        $customer = $this->customer('Cliente Actualizar ' . uniqid());

        $result = $this->updateTool()->__invoke(organization_id: (int) $customer->getId(), tax_id: '131794321');

        $this->assertSame(self::RNC, $result['tax_id']);
        $this->assertSame(self::LEGAL_NAME, $result['dgii_legal_name']);

        $this->updateTool()->__invoke(organization_id: (int) $customer->getId(), phone: '8095550000');
        $this->assertSame(self::RNC, $customer->refresh()->tax_id);
        $this->assertSame(TaxIdTypeEnum::DO_RNC, $customer->tax_id_type);
    }

    public function test_update_refuses_an_unregistered_rnc_without_touching_the_record(): void
    {
        $customer = $this->customer('Cliente Intacto ' . uniqid());

        $result = $this->updateTool()->__invoke(organization_id: (int) $customer->getId(), tax_id: '101000001');

        $this->assertFalse($result['success']);
        $this->assertNull($customer->refresh()->tax_id);
    }

    public function test_create_fills_the_tax_id_of_an_existing_organization_it_matches(): void
    {
        $customer = $this->customer('Cliente Existente ' . uniqid());

        $result = $this->createTool()->__invoke(name: $customer->name, tax_id: '131794321');

        $this->assertFalse($result['created']);
        $this->assertSame((int) $customer->getId(), $result['organization_id']);
        $this->assertSame(self::RNC, $customer->refresh()->tax_id);
    }

    /**
     * The real case: an approved AR invoice that needs the customer's RNC fails until the agent saves it,
     * then issues with the RNC snapshotted from the column.
     */
    public function test_an_invoice_blocked_on_a_missing_rnc_issues_once_the_agent_saves_it(): void
    {
        $this->artisan('kanvas-scribe:seed-ecf-approval-policy', [
            'app_id' => $this->kanvasApp->getId(),
            'company_id' => $this->company->getId(),
        ])->assertSuccessful();

        $customer = $this->customer('Cliente Sin RNC ' . uniqid());
        $draft = $this->draftInvoice($customer, null);
        $draft->regional_compliance = ['do_ecf' => ['type' => '31']];
        $draft->saveOrFail();

        $invoice = $this->submitAsAgent($draft);
        $this->assertSame('approve_invoice', $invoice->pendingApproval()?->approval_type);

        try {
            new ApproveAction($invoice->pendingApproval(), static::$cachedUser)->execute();
        } catch (Throwable $e) {
            $this->assertStringContainsString("requires the customer's RNC", $e->getMessage());
        }
        $this->assertNotSame(InvoiceDocumentStatusEnum::ISSUED, $invoice->refresh()->document_status);

        $saved = $this->updateTool()->__invoke(organization_id: (int) $customer->getId(), tax_id: '131-79432-1');
        $this->assertSame(self::LEGAL_NAME, $saved['dgii_legal_name']);

        $pending = $invoice->refresh()->pendingApproval() ?? $this->submitAsAgent($invoice)->pendingApproval();
        new ApproveAction($pending, static::$cachedUser)->execute();

        $invoice->refresh();
        $this->assertSame(InvoiceDocumentStatusEnum::ISSUED, $invoice->document_status);
        $this->assertStringStartsWith('E31', (string) $invoice->invoice_number);
        $this->assertSame(self::RNC, $invoice->billable_tax_id);
    }

    /**
     * @return array<string, array{class-string<SystemUserAgent>}>
     */
    public static function accountingAgents(): array
    {
        return [
            'accounts receivable' => [AccountsReceivableAgent::class],
            'accounts payable' => [AccountsPayableAgent::class],
        ];
    }

    #[DataProvider('accountingAgents')]
    public function test_the_ar_and_ap_agents_can_manage_organizations(string $handlerClass): void
    {
        $handler = new $handlerClass();
        $agent = Agent::factory()
            ->withAppId($this->kanvasApp->getId())
            ->withCompanyId($this->company->getId())
            ->create(['user_id' => static::$cachedUser->getId()]);
        $handler->setConfiguration(agent: $agent, user: static::$cachedUser);

        $names = array_map(
            static fn (object $tool): string => (string) $tool->getName(),
            new ReflectionMethod($handler, 'tools')->invoke($handler),
        );

        foreach (['create_organization', 'update_organization', 'get_organization', 'set_organization_address', 'check_rnc'] as $tool) {
            $this->assertContains($tool, $names, "{$handlerClass} should hold {$tool}");
        }
    }

    private function createTool(): CreateOrganizationTool
    {
        return new CreateOrganizationTool()->withContext($this->kanvasApp, $this->company, static::$cachedUser);
    }

    private function updateTool(): UpdateOrganizationTool
    {
        return new UpdateOrganizationTool()->withContext($this->kanvasApp, $this->company, static::$cachedUser);
    }

    private function submitAsAgent(Invoice $draft): Invoice
    {
        return ApprovalOriginService::during(
            ApprovalOriginEnum::AGENT,
            fn (): Invoice => new SubmitInvoiceForApprovalAction($draft, static::$cachedUser)->execute()
        );
    }

    private function organizationsNamed(string $name): int
    {
        return Organization::query()
            ->where('companies_id', $this->company->getId())
            ->where('name', $name)
            ->count();
    }
}
