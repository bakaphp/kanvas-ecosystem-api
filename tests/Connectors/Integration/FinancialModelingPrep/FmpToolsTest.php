<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\FinancialModelingPrep;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\FinancialModelingPrep\Client;
use Kanvas\Connectors\FinancialModelingPrep\Enums\ConfigurationEnum;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Intelligence\Agents\Laravel\Tools\FinancialModelingPrep\FmpCompanyProfileTool;
use Kanvas\Intelligence\Agents\Laravel\Tools\FinancialModelingPrep\FmpCompanySearchTool;
use Kanvas\Intelligence\Agents\Laravel\Tools\FinancialModelingPrep\FmpFinancialSnapshotTool;
use Kanvas\Intelligence\Agents\Laravel\Tools\FinancialModelingPrep\FmpInstitutionalOwnershipTool;
use Laravel\Ai\Tools\Request;
use Tests\TestCase;

final class FmpToolsTest extends TestCase
{
    use DatabaseTransactions;

    private Apps $kanvasApp;
    private Companies $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->kanvasApp = app(Apps::class);
        $this->company = static::$cachedUser->getCurrentCompany();
    }

    public function testClientThrowsWhenApiKeyNotSet(): void
    {
        $blankApp = \Mockery::mock(Apps::class);
        $blankApp->allows('get')->andReturnNull();
        $blankApp->allows('getAttribute')->andReturnNull();

        $this->expectException(ValidationException::class);

        new Client($blankApp);
    }

    public function testClientInstantiatesWhenKeyIsSet(): void
    {
        if (empty($this->kanvasApp->get(ConfigurationEnum::FMP_API_KEY->value))) {
            $this->markTestSkipped('FMP API key not configured on the app.');
        }

        $this->assertInstanceOf(Client::class, new Client($this->kanvasApp));
    }

    public function testCompanySearchToolHasDescription(): void
    {
        $tool = new FmpCompanySearchTool();
        $this->assertNotEmpty($tool->description());
    }

    public function testCompanyProfileToolHasDescription(): void
    {
        $tool = new FmpCompanyProfileTool();
        $this->assertNotEmpty($tool->description());
    }

    /**
     * Integration test — requires TEST_FMP_API_KEY env var.
     */
    public function testClientValidatesApiKey(): void
    {
        $apiKey = getenv('TEST_FMP_API_KEY');

        if (empty($apiKey)) {
            $this->markTestSkipped('TEST_FMP_API_KEY not set.');
        }

        $this->assertTrue(Client::validateCredentials($apiKey));
        $this->assertFalse(Client::validateCredentials('invalid-key-xyz'));
    }

    /**
     * Integration test — requires TEST_FMP_API_KEY env var.
     */
    public function testCompanySearchToolReturnsResults(): void
    {
        $apiKey = getenv('TEST_FMP_API_KEY');

        if (empty($apiKey)) {
            $this->markTestSkipped('TEST_FMP_API_KEY not set.');
        }

        $this->kanvasApp->set(ConfigurationEnum::FMP_API_KEY->value, $apiKey);

        $tool = (new FmpCompanySearchTool())->withContext($this->kanvasApp, $this->company);
        $result = json_decode($tool->handle(new Request(['query' => 'Apple', 'limit' => 3])), true);

        $this->assertArrayHasKey('companies', $result);
        $this->assertNotEmpty($result['companies']);
        $this->assertArrayHasKey('symbol', $result['companies'][0]);
    }

    /**
     * Integration test — requires TEST_FMP_API_KEY env var.
     */
    public function testCompanyProfileToolReturnsProfile(): void
    {
        $apiKey = getenv('TEST_FMP_API_KEY');

        if (empty($apiKey)) {
            $this->markTestSkipped('TEST_FMP_API_KEY not set.');
        }

        $this->kanvasApp->set(ConfigurationEnum::FMP_API_KEY->value, $apiKey);

        $tool = (new FmpCompanyProfileTool())->withContext($this->kanvasApp, $this->company);
        $result = json_decode($tool->handle(new Request(['symbol' => 'AAPL'])), true);

        $this->assertArrayHasKey('symbol', $result);
        $this->assertSame('AAPL', $result['symbol']);
        $this->assertArrayHasKey('sector', $result);
    }

    public function testFinancialSnapshotToolHasDescription(): void
    {
        $tool = new FmpFinancialSnapshotTool();
        $this->assertNotEmpty($tool->description());
        $this->assertNotEmpty($tool->name());
        $this->assertStringContainsString('fmp_financial_snapshot', $tool->name());
    }

    /**
     * Integration test — requires TEST_FMP_API_KEY env var.
     */
    public function testFinancialSnapshotToolReturnsStructuredMetrics(): void
    {
        $apiKey = getenv('TEST_FMP_API_KEY');

        if (empty($apiKey)) {
            $this->markTestSkipped('TEST_FMP_API_KEY not set.');
        }

        $this->kanvasApp->set(ConfigurationEnum::FMP_API_KEY->value, $apiKey);

        $tool = (new FmpFinancialSnapshotTool())->withContext($this->kanvasApp, $this->company);
        $result = json_decode($tool->handle(new Request(['symbol' => 'AAPL'])), true);

        $this->assertArrayNotHasKey('error', $result);
        $this->assertArrayHasKey('symbol', $result);
        $this->assertSame('AAPL', $result['symbol']);
        $this->assertArrayHasKey('currency', $result);
        $this->assertArrayHasKey('metrics', $result);

        $expectedMetrics = ['revenue', 'ebitda', 'interest_expense', 'cash', 'total_debt', 'stock_price'];
        foreach ($expectedMetrics as $metric) {
            $this->assertArrayHasKey($metric, $result['metrics'], "Missing metric: {$metric}");
            $this->assertArrayHasKey('current', $result['metrics'][$metric]);
            $this->assertArrayHasKey('previous', $result['metrics'][$metric]);
            $this->assertArrayHasKey('change_pct', $result['metrics'][$metric]);
            $this->assertArrayHasKey('as_of_date', $result['metrics'][$metric]);
        }

        $this->assertNotNull($result['metrics']['revenue']['current']);
        $this->assertNotNull($result['metrics']['revenue']['previous']);
        $this->assertNotNull($result['metrics']['revenue']['change_pct']);
    }

    public function testInstitutionalOwnershipToolHasDescription(): void
    {
        $tool = new FmpInstitutionalOwnershipTool();
        $this->assertNotEmpty($tool->description());
        $this->assertNotEmpty($tool->name());
        $this->assertStringContainsString('fmp_institutional_ownership', $tool->name());
    }

    /**
     * Integration test — requires TEST_FMP_API_KEY env var.
     */
    public function testInstitutionalOwnershipToolReturnsTopHolders(): void
    {
        $apiKey = getenv('TEST_FMP_API_KEY');

        if (empty($apiKey)) {
            $this->markTestSkipped('TEST_FMP_API_KEY not set.');
        }

        $this->kanvasApp->set(ConfigurationEnum::FMP_API_KEY->value, $apiKey);

        $tool = (new FmpInstitutionalOwnershipTool())->withContext($this->kanvasApp, $this->company);
        $result = json_decode($tool->handle(new Request(['symbol' => 'AAPL'])), true);

        $this->assertArrayNotHasKey('error', $result);
        $this->assertArrayHasKey('symbol', $result);
        $this->assertSame('AAPL', $result['symbol']);
        $this->assertArrayHasKey('top_holders', $result);
        $this->assertIsArray($result['top_holders']);
        $this->assertLessThanOrEqual(3, count($result['top_holders']));

        if (! empty($result['top_holders'])) {
            $holder = $result['top_holders'][0];
            $this->assertArrayHasKey('holder', $holder);
            $this->assertArrayHasKey('shares', $holder);
            $this->assertArrayHasKey('date_reported', $holder);
            $this->assertArrayHasKey('change', $holder);
        }
    }
}
