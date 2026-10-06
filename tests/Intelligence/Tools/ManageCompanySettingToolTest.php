<?php

declare(strict_types=1);

namespace Tests\Intelligence\Tools;

use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Intelligence\Agents\Neuron\Tools\Company\ManageCompanySettingTool;
use Kanvas\Users\Models\Users;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

class ManageCompanySettingToolTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    private function tool(Companies $company, bool $admin = true): ManageCompanySettingTool
    {
        $company->shouldReceive('getId')->andReturn(12);
        $actor = Mockery::mock(Users::class);
        $actor->shouldReceive('isAdmin')->andReturn($admin);
        return new ManageCompanySettingTool()
            ->withContext(Mockery::mock(Apps::class), $company, Mockery::mock(Users::class))
            ->forRequestingUser($actor);
    }

    public function testWritesAnArbitrarySettingAsPrivateJson(): void
    {
        $company = Mockery::mock(Companies::class);
        $value = ['timezone' => 'America/Santo_Domingo', 'days' => ['Monday'], 'enabled' => false];
        $company->shouldReceive('set')->once()->with('custom_business_config', $value, false)->andReturn(true);
        $result = $this->tool($company)('set', 'custom_business_config', json_encode($value));
        self::assertTrue($result['success']);
        self::assertSame($value, $result['value']);
    }

    public function testReadsAnArbitrarySetting(): void
    {
        $company = Mockery::mock(Companies::class);
        $company->shouldReceive('get')->once()->with('custom_flag')->andReturn(false);
        $result = $this->tool($company)('get', 'custom_flag');
        self::assertTrue($result['configured']);
        self::assertFalse($result['value']);
    }

    public function testCredentialsNeverAppearInReadOutputAndCannotBeWritten(): void
    {
        $company = Mockery::mock(Companies::class);
        $company->shouldReceive('get')->once()->with('custom_api_key')->andReturn('test-secret-not-a-real-key');
        $tool = $this->tool($company);
        $result = $tool('get', 'custom_api_key');
        self::assertTrue($result['configured']);
        self::assertArrayNotHasKey('value', $result);
        self::assertStringNotContainsString('test-secret', json_encode($result));
        self::assertFalse($tool('set', 'custom_api_key', '"replacement"')['success']);
    }

    public function testRejectsInvalidValuesWithoutWriting(): void
    {
        $tool = $this->tool(Mockery::mock(Companies::class));
        foreach ([['ai', '"true"'], ['twilio_batch_delay_seconds', '-1'], ['anything', '{bad'],
            ['anything', 'null'], ['', 'true'], ['twilio_phone_number', '"invalid"'],
            ['adf_sources', '[{"Source":"missing-fields"}]']] as [$key, $json]) {
            self::assertFalse($tool('set', $key, $json)['success']);
        }
    }

    public function testRequiresIdentifiedAdminEvenIfTheAgentUserIsAdmin(): void
    {
        $company = Mockery::mock(Companies::class);
        $company->shouldReceive('getId')->andReturn(12);
        $agentUser = Mockery::mock(Users::class);
        $agentUser->shouldNotReceive('isAdmin');
        $tool = new ManageCompanySettingTool()->withContext(Mockery::mock(Apps::class), $company, $agentUser);
        self::assertFalse($tool('set', 'ai', 'true')['success']);
        self::assertFalse($this->tool(Mockery::mock(Companies::class), false)('get', 'ai')['success']);
    }

    public function testRejectsMissingAndGlobalCompanyContext(): void
    {
        self::assertFalse(new ManageCompanySettingTool()('catalog')['success']);
        $company = Mockery::mock(Companies::class);
        $company->shouldReceive('getId')->andReturn(0);
        $tool = new ManageCompanySettingTool()->withContext(Mockery::mock(Apps::class), $company, Mockery::mock(Users::class));
        self::assertFalse($tool('set', 'ai', 'true')['success']);
    }

    public function testReferenceValidationRejectsAnUnresolvedAgent(): void
    {
        $tool = new class extends ManageCompanySettingTool {
            protected function localAgentReferenceExists(string $type, int $value): bool
            {
                return false;
            }
        };
        $company = Mockery::mock(Companies::class);
        $company->shouldReceive('getId')->andReturn(12);
        $admin = Mockery::mock(Users::class);
        $admin->shouldReceive('isAdmin')->andReturn(true);
        $tool->withContext(Mockery::mock(Apps::class), $company, $admin)->forRequestingUser($admin);
        self::assertFalse($tool('set', 'agent_reach_out_default_agent_id', '999')['success']);
        self::assertFalse($tool('set', 'ai-agent-user-id', '999')['success']);
    }
}
