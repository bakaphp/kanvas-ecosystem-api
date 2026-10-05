<?php

declare(strict_types=1);

namespace Tests\Intelligence\Agents;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Kanvas\Apps\Models\Apps;
use Kanvas\Intelligence\Agents\Enums\AgentRunConfigurationEnum;
use Kanvas\Intelligence\Agents\Services\ModelContextWindowService;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * Serial: an app setting (Redis). The platform cap stays the default for everyone; one app that pays for
 * a wider window sets its own, and the model ceiling still bounds it.
 */
#[Group('serial')]
class AppHistoryWindowOverrideTest extends TestCase
{
    use DatabaseTransactions;

    protected array $connectionsToTransact = ['mysql', 'intelligence'];

    private Apps $kanvasApp;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        $this->kanvasApp = app(Apps::class);
        $this->kanvasApp->del(AgentRunConfigurationEnum::MAX_HISTORY_TOKENS->value);

        DB::connection('intelligence')->table('model_pricing')->insert([
            'provider' => 'gemini',
            'model' => 'gemini-wide-appcap-test',
            'input_per_million' => 1.25,
            'output_per_million' => 10.0,
            'max_input_tokens' => 1_048_576,
            'effective_from' => Carbon::now()->toDateString(),
            'source' => 'injected',
            'is_deleted' => 0,
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ]);
    }

    protected function tearDown(): void
    {
        $this->kanvasApp->del(AgentRunConfigurationEnum::MAX_HISTORY_TOKENS->value);

        parent::tearDown();
    }

    public function testAnAppWithoutItsOwnCapGetsThePlatformDefault(): void
    {
        config(['kanvas.agents.max_history_tokens' => 50_000]);

        $this->assertSame(50_000, ModelContextWindowService::forModel('gemini', 'gemini-wide-appcap-test', $this->kanvasApp));
    }

    public function testAnAppCanRaiseItsOwnCapWithinTheModelCeiling(): void
    {
        config(['kanvas.agents.max_history_tokens' => 50_000]);
        $this->kanvasApp->set(AgentRunConfigurationEnum::MAX_HISTORY_TOKENS->value, 120_000);

        $this->assertSame(120_000, ModelContextWindowService::forModel('gemini', 'gemini-wide-appcap-test', $this->kanvasApp));
        $this->assertSame(50_000, ModelContextWindowService::forModel('gemini', 'gemini-wide-appcap-test'), 'Another app still gets the platform default');
    }

    public function testAZeroSettingMeansTheDefault(): void
    {
        config(['kanvas.agents.max_history_tokens' => 50_000]);
        $this->kanvasApp->set(AgentRunConfigurationEnum::MAX_HISTORY_TOKENS->value, 0);

        $this->assertSame(50_000, ModelContextWindowService::forModel('gemini', 'gemini-wide-appcap-test', $this->kanvasApp));
    }
}
