<?php

declare(strict_types=1);

namespace Tests\Connectors\Movipass;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Storage;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Movipass\Neuron\Tools\ExportPaymentsFinancialReportTool;
use Kanvas\Filesystem\Services\FilesystemServices;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Models\AgentType;
use Kanvas\Intelligence\Agents\Neuron\Commerce\ShoppingAssistantAgent;
use Kanvas\Users\Models\Users;
use Maatwebsite\Excel\Facades\Excel;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;
use Tests\TestCase;

#[Group('serial')]
final class ExportPaymentsFinancialReportToolTest extends TestCase
{
    use DatabaseTransactions;

    protected array $connectionsToTransact = ['mysql', 'commerce', 'inventory'];

    private Apps $currentApp;
    private Companies $company;
    private Users $user;
    private mixed $originalMainCompanyId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->currentApp = app(Apps::class);
        $this->user = auth()->user();
        $this->company = $this->user->getCurrentCompany();

        $this->originalMainCompanyId = $this->currentApp->get('B2B_MAIN_COMPANY_ID');
        $this->currentApp->set('B2B_MAIN_COMPANY_ID', $this->company->getId());
    }

    protected function tearDown(): void
    {
        if ($this->originalMainCompanyId === null) {
            $this->currentApp->del('B2B_MAIN_COMPANY_ID');
        } else {
            $this->currentApp->set('B2B_MAIN_COMPANY_ID', $this->originalMainCompanyId);
        }

        parent::tearDown();
    }

    private function tool(?Agent $agent = null): ExportPaymentsFinancialReportTool
    {
        return new ExportPaymentsFinancialReportTool()->withContext(
            $this->currentApp,
            $this->company,
            $this->user,
            $agent,
        );
    }

    public function testExportReturnsADownloadLinkForTheRequestedWindow(): void
    {
        $public = Storage::fake('public');
        $platform = Storage::fake(FilesystemServices::PLATFORM_DISK);
        $appDisk = Storage::fake('financial-report');

        $storage = Storage::partialMock();
        $storage->shouldReceive('build')->andReturn($appDisk);
        $storage->set('public', $public);
        $storage->set(FilesystemServices::PLATFORM_DISK, $platform);

        $result = $this->tool()->__invoke(since: '2026-10-01', until: '2026-10-31', timezone: 'America/Santo_Domingo');

        $this->assertSame('success', $result['status']);
        $this->assertNotEmpty($result['download_url']);
        $this->assertSame('payments_financial_report_2026-10-01_2026-10-31.xlsx', $result['file_name']);
    }

    public function testAFailedExportComesBackAsAnErrorAndCanBeRetried(): void
    {
        Excel::shouldReceive('store')->twice()->andThrow(new RuntimeException('disk unavailable'));

        $tool = $this->tool();

        $first = $tool->__invoke(since: '2026-10-01', until: '2026-10-31');
        $this->assertSame('error', $first['status']);
        $this->assertStringContainsString('could not be generated', $first['message']);

        $retry = $tool->__invoke(since: '2026-10-01', until: '2026-10-31');
        $this->assertArrayNotHasKey('repeat_call', $retry);
    }

    public function testRefusesWhenTheAppIsNotOnTheProviderModel(): void
    {
        $this->currentApp->del('B2B_MAIN_COMPANY_ID');

        $result = $this->tool()->__invoke(since: '2026-10-01', until: '2026-10-31');

        $this->assertSame('error', $result['status']);
        $this->assertStringContainsString('B2B_MAIN_COMPANY_ID', $result['message']);
    }

    public function testRefusesOnACustomerFacingAgent(): void
    {
        $type = new AgentType();
        $type->handler = ShoppingAssistantAgent::class;

        $agent = new Agent();
        $agent->setRelation('type', $type);

        $result = $this->tool($agent)->__invoke(since: '2026-10-01', until: '2026-10-31');

        $this->assertSame('error', $result['status']);
        $this->assertStringContainsString('internal to the team', $result['message']);
    }

    public function testRejectsAnUnknownOrderTypeOrChannel(): void
    {
        $orderType = $this->tool()->__invoke(order_type: 'valet');
        $this->assertSame('error', $orderType['status']);
        $this->assertStringContainsString('order_type must be one of', $orderType['message']);

        $channel = $this->tool()->__invoke(payment_channel: 'crypto');
        $this->assertSame('error', $channel['status']);
        $this->assertStringContainsString('payment_channel must be one of', $channel['message']);
    }

    public function testRejectsAnInvertedOrUnreadableWindow(): void
    {
        $inverted = $this->tool()->__invoke(since: '2026-10-31', until: '2026-10-01');
        $this->assertSame('error', $inverted['status']);
        $this->assertStringContainsString('on or before', $inverted['message']);

        $unreadable = $this->tool()->__invoke(since: 'not a date');
        $this->assertSame('error', $unreadable['status']);
        $this->assertStringContainsString('YYYY-MM-DD', $unreadable['message']);
    }

    public function testRejectsAVariantOutsideTheApp(): void
    {
        $result = $this->tool()->__invoke(since: '2026-10-01', until: '2026-10-31', variant_id: PHP_INT_MAX);

        $this->assertSame('error', $result['status']);
        $this->assertStringContainsString((string) PHP_INT_MAX, $result['message']);
    }

    public function testARepeatedCallInTheSameTurnDoesNotExportTwice(): void
    {
        $tool = $this->tool();

        $first = $tool->__invoke(order_type: 'valet');
        $second = $tool->__invoke(order_type: 'valet');

        $this->assertArrayNotHasKey('repeat_call', $first);
        $this->assertTrue($second['repeat_call']);
    }
}
