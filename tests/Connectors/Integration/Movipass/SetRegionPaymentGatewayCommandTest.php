<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Movipass;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Currencies\Models\Currencies;
use Kanvas\Regions\Models\Regions;
use Tests\TestCase;

final class SetRegionPaymentGatewayCommandTest extends TestCase
{
    use DatabaseTransactions;

    protected array $connectionsToTransact = ['mysql', 'ecosystem'];

    private Apps $appInstance;
    private Regions $sv;
    private Regions $pr;

    protected function setUp(): void
    {
        parent::setUp();

        if (getenv('GITHUB_ACTIONS')) {
            $this->markTestSkipped('Movipass region tests are skipped in CI');
        }

        $this->appInstance = app(Apps::class);
        $currency = Currencies::getByCode('USD');

        $this->sv = $this->makeRegion('test-sv', 'TSV', $currency->getId(), [
            'language' => 'es-SV',
            'timezone' => 'America/El_Salvador',
            'default_payment_gateway' => 'stripe',
            'payment_gateways' => ['stripe'],
        ]);

        $this->pr = $this->makeRegion('test-pr', 'TPR', $currency->getId(), [
            'language' => 'es-PR',
            'timezone' => 'America/Puerto_Rico',
            'default_payment_gateway' => 'stripe',
            'payment_gateways' => ['stripe'],
        ]);
    }

    public function testSetsGatewayForTargetRegionOnly(): void
    {
        $this->artisan('kanvas-movipass:set-region-gateway', [
            'app_id' => $this->appInstance->getId(),
            'region' => 'TSV',
            'gateway' => 'payway',
        ])->assertExitCode(0);

        $this->sv->refresh();
        $this->pr->refresh();

        $this->assertEquals('payway', $this->sv->settings['default_payment_gateway']);
        $this->assertEquals(['payway'], $this->sv->settings['payment_gateways']);
        $this->assertEquals('stripe', $this->pr->settings['default_payment_gateway']);
    }

    public function testPreservesOtherSettingsKeys(): void
    {
        $this->artisan('kanvas-movipass:set-region-gateway', [
            'app_id' => $this->appInstance->getId(),
            'region' => 'el-salvador-fake',
            'gateway' => 'payway',
        ])->assertExitCode(1);

        $this->artisan('kanvas-movipass:set-region-gateway', [
            'app_id' => $this->appInstance->getId(),
            'region' => 'test-sv',
            'gateway' => 'payway',
        ])->assertExitCode(0);

        $this->sv->refresh();
        $this->assertEquals('payway', $this->sv->settings['default_payment_gateway']);
        $this->assertEquals('America/El_Salvador', $this->sv->settings['timezone']);
        $this->assertEquals('es-SV', $this->sv->settings['language']);
    }

    public function testIsIdempotent(): void
    {
        for ($i = 0; $i < 2; $i++) {
            $this->artisan('kanvas-movipass:set-region-gateway', [
                'app_id' => $this->appInstance->getId(),
                'region' => 'TSV',
                'gateway' => 'payway',
            ])->assertExitCode(0);
        }

        $this->sv->refresh();
        $this->assertEquals(['payway'], $this->sv->settings['payment_gateways']);
    }

    public function testGatewaysOptionOverridesList(): void
    {
        $this->artisan('kanvas-movipass:set-region-gateway', [
            'app_id' => $this->appInstance->getId(),
            'region' => 'TSV',
            'gateway' => 'payway',
            '--gateways' => ['payway', 'stripe'],
        ])->assertExitCode(0);

        $this->sv->refresh();
        $this->assertEquals('payway', $this->sv->settings['default_payment_gateway']);
        $this->assertEquals(['payway', 'stripe'], $this->sv->settings['payment_gateways']);
    }

    private function makeRegion(string $slug, string $shortSlug, int $currencyId, array $settings): Regions
    {
        $region = new Regions();
        $region->apps_id = $this->appInstance->getId();
        $region->companies_id = 0;
        $region->users_id = 0;
        $region->name = $slug;
        $region->slug = $slug;
        $region->short_slug = $shortSlug;
        $region->currency_id = $currencyId;
        $region->is_default = 0;
        $region->settings = $settings;
        $region->saveOrFail();

        return $region;
    }
}
