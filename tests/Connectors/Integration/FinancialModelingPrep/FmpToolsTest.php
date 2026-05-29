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
}
