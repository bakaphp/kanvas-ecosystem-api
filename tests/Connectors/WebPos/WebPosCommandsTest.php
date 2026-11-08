<?php

declare(strict_types=1);

namespace Tests\Connectors\WebPos;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\WebPos\Enums\ConfigurationEnum;
use Kanvas\Connectors\WebPos\Jobs\PullReceivedEcfsJob;
use Kanvas\Scribe\ElectronicInvoicing\Enums\ConfigurationEnum as EcfConfigurationEnum;
use Tests\Connectors\Traits\HasWebPosConfiguration;
use Tests\TestCase;

final class WebPosCommandsTest extends TestCase
{
    use DatabaseTransactions;
    use HasWebPosConfiguration;

    protected array $connectionsToTransact = ['mysql', 'ecosystem', 'workflow'];

    public function testSetupStoresTheSettingsMakesWebPosTheProviderAndNeverPrintsTheKey(): void
    {
        Http::fake([self::WEBPOS_TEST_HOST . '/api/fedo/ak/v1/checkRnc/*' => Http::response(['valido' => true, 'razonSocial' => 'Ejemplo SRL'])]);
        $company = $this->freshCompany();

        $this->artisan('kanvas:webpos-setup', [
            'app_id' => app(Apps::class)->getId(),
            'company_id' => $company->getId(),
            '--api-key' => self::WEBPOS_API_KEY,
            '--license' => 'YPSPUFLH',
            '--branch' => '0001',
            '--pos' => '001',
            '--rnc' => '131794321',
        ])
            ->expectsOutputToContain('API key verified')
            ->doesntExpectOutputToContain(self::WEBPOS_API_KEY)
            ->assertSuccessful();

        $company = Companies::getById($company->getId());
        $this->assertSame('webpos', $company->get(EcfConfigurationEnum::ECF_PROVIDER->value));
        $this->assertSame('test', $company->get(EcfConfigurationEnum::ECF_ENVIRONMENT->value));
        $this->assertSame('YPSPUFLH', $company->get(ConfigurationEnum::COMPANY_LICENSE->value));
        $this->assertSame(self::WEBPOS_API_KEY, $company->get(ConfigurationEnum::API_KEY->value));
        $this->assertTrue($company->isSecret(ConfigurationEnum::API_KEY->value), 'the API key is stored encrypted');
        Http::assertSent(fn (Request $request): bool => $request->url() === self::WEBPOS_TEST_HOST
            . '/api/fedo/ak/v1/checkRnc/' . self::WEBPOS_API_KEY . '/131794321');
        $this->assertSame(app(Apps::class)->getId(), (int) $company->get(ConfigurationEnum::APP_ID->value));
    }

    public function testSetupFailsWhenWebPosRejectsTheKey(): void
    {
        Http::fake([self::WEBPOS_TEST_HOST . '/api/fedo/ak/v1/checkRnc/*' => Http::response(['message' => 'ApiKey invalid / ApiKey inválido'], 400)]);
        $company = $this->freshCompany();

        $this->artisan('kanvas:webpos-setup', [
            'app_id' => app(Apps::class)->getId(),
            'company_id' => $company->getId(),
            '--api-key' => self::WEBPOS_API_KEY,
            '--license' => 'YPSPUFLH',
            '--branch' => '0001',
            '--pos' => '001',
            '--rnc' => '131794321',
        ])
            ->expectsOutputToContain('WebPOS setup failed')
            ->doesntExpectOutputToContain(self::WEBPOS_API_KEY)
            ->assertFailed();
    }

    public function testThePullCommandQueuesOneJobPerWebPosCompany(): void
    {
        Queue::fake();

        $company = $this->webPosCompany();
        $company->set(EcfConfigurationEnum::ECF_PROVIDER->value, 'webpos');
        $company->set(ConfigurationEnum::APP_ID->value, app(Apps::class)->getId());

        $this->artisan('kanvas:webpos-pull-received', ['--company_id' => $company->getId()])->assertSuccessful();

        Queue::assertPushed(
            PullReceivedEcfsJob::class,
            fn (PullReceivedEcfsJob $job): bool => $job->company->getId() === $company->getId()
        );
    }

    private function freshCompany(): Companies
    {
        /** @var Companies $company */
        $company = Companies::factory()->create(['users_id' => Auth::id(), 'is_active' => 1]);
        $company->associateApp(app(Apps::class));

        return $company;
    }
}
