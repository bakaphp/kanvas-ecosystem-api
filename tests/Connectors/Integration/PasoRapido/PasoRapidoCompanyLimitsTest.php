<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\PasoRapido;

use Baka\Support\IPInfo;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\PasoRapido\Client;
use Kanvas\Connectors\PasoRapido\Enums\CompanySettingsEnum;
use Kanvas\Connectors\PasoRapido\Enums\ConfigurationEnum;
use Kanvas\Connectors\PasoRapido\Services\PasoRapidoService;
use Kanvas\Exceptions\ValidationException;
use Mockery;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Tests\TestCase;

final class PasoRapidoCompanyLimitsTest extends TestCase
{
    private const TAG = '941001';

    private Apps $kanvasApp;
    private Companies $company;
    private int $userId;

    /** @var array<string, mixed> */
    private array $originalAppSettings = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->kanvasApp = app(Apps::class);
        $this->userId = auth()->user()->getId();
        $this->company = Companies::factory()->create(['users_id' => $this->userId]);

        // Both gates are app-wide and run before the company checks under test.
        $this->neutralizeAppSetting(ConfigurationEnum::VERIFY_TAG_ATTRIBUTE_SLUG);
        $this->neutralizeAppSetting(ConfigurationEnum::VERIFY_REQUIRE_VERIFIED_ACCOUNT);

        $this->clearCounters();
    }

    protected function tearDown(): void
    {
        foreach ($this->originalAppSettings as $key => $value) {
            $value === null ? $this->kanvasApp->del($key) : $this->kanvasApp->set($key, $value);
        }

        $this->company->deleteAllSettings();
        $this->clearCounters();

        parent::tearDown();
    }

    public function testBlockedCompanyNeverReachesTheApi(): void
    {
        $this->company->set(CompanySettingsEnum::VERIFY_BLOCKED->value, '1');

        $client = Mockery::mock(Client::class);
        $client->shouldNotReceive('post');

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Tag verification is disabled for this company.');

        $this->service($client)->verifyCustomer(self::TAG);
    }

    public function testBlockedCompanyReturnsTheConfiguredReason(): void
    {
        $this->company->set(CompanySettingsEnum::VERIFY_BLOCKED->value, '1');
        $this->company->set(CompanySettingsEnum::VERIFY_BLOCKED_REASON->value, 'Cuenta suspendida por falta de pago.');

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Cuenta suspendida por falta de pago.');

        $this->service()->verifyCustomer(self::TAG);
    }

    public function testUnblockedCompanyPassesThrough(): void
    {
        $result = $this->service()->verifyCustomer(self::TAG);

        $this->assertSame(self::TAG, $result->device);
    }

    public function testCompanyOverrideCapsCorporateRequestsPerMinute(): void
    {
        $this->company->set('is_corporate', '1');
        $this->company->set(CompanySettingsEnum::VERIFY_MAX_ATTEMPTS->value, '1');

        $service = $this->service();
        $service->verifyCustomer(self::TAG);

        $this->expectException(TooManyRequestsHttpException::class);

        $service->verifyCustomer(self::TAG);
    }

    public function testCompanyOverrideRaisesTheRetailPerMinuteCap(): void
    {
        // is_corporate pins which company resolveCorporateCompany() picks. Without it the
        // resolver falls back to any corporate company the shared test user belongs to, and
        // the override under test lands on a company the service never reads.
        $this->company->set('is_corporate', '1');
        $this->company->set(CompanySettingsEnum::VERIFY_MAX_ATTEMPTS->value, '8');

        $service = $this->service();

        // Six calls clear the corporate default of 5 only because of the override.
        for ($i = 0; $i < 5; $i++) {
            $service->verifyCustomer(self::TAG);
        }

        $result = $service->verifyCustomer(self::TAG);

        $this->assertSame(self::TAG, $result->device);
    }

    public function testCorporateIsNowSubjectToTheDailyCap(): void
    {
        $this->company->set('is_corporate', '1');
        $this->company->set(CompanySettingsEnum::VERIFY_MAX_DAILY->value, '2');

        $service = $this->service();
        $service->verifyCustomer(self::TAG);
        $service->verifyCustomer(self::TAG);

        $this->expectException(TooManyRequestsHttpException::class);
        $this->expectExceptionMessage('Daily tag verification limit reached.');

        $service->verifyCustomer(self::TAG);
    }

    public function testCorporateIsNowSubjectToTheIpUserCap(): void
    {
        $this->company->set('is_corporate', '1');
        $this->company->set(CompanySettingsEnum::VERIFY_IP_MAX_USERS->value, '1');

        $appId = $this->kanvasApp->getId();
        $ip = IPInfo::getClientIp();
        Cache::put("paso-rapido-ip-users:{$appId}:{$ip}", [$this->userId, $this->userId + 1], 86400);

        $this->expectException(TooManyRequestsHttpException::class);
        $this->expectExceptionMessage('Suspicious activity detected.');

        $this->service()->verifyCustomer(self::TAG);
    }

    public function testCorporateDefaultsToFivePerMinute(): void
    {
        $this->company->set('is_corporate', '1');

        $service = $this->service();

        for ($i = 0; $i < 5; $i++) {
            $service->verifyCustomer(self::TAG);
        }

        $this->expectException(TooManyRequestsHttpException::class);
        $this->expectExceptionMessage('Too many tag verification requests.');

        $service->verifyCustomer(self::TAG);
    }

    public function testCorporateSkipsSequentialDetectionByDefault(): void
    {
        $this->company->set('is_corporate', '1');
        $this->company->set(CompanySettingsEnum::VERIFY_MAX_ATTEMPTS->value, '0');

        // Fleets buy TAG blocks, so consecutive numbers are legitimate for corporate.
        $service = $this->service();

        foreach (['941637', '941638', '941639', '941640', '941641'] as $tag) {
            $result = $service->verifyCustomer($tag);
        }

        $this->assertNotNull($result);
    }

    public function testSequentialDetectionCanBeTurnedOnPerCompany(): void
    {
        $this->company->set('is_corporate', '1');
        $this->company->set(CompanySettingsEnum::VERIFY_SEQUENTIAL_THRESHOLD->value, '3');

        $service = $this->service();
        $service->verifyCustomer('941637');
        $service->verifyCustomer('941638');

        $this->expectException(TooManyRequestsHttpException::class);
        $this->expectExceptionMessage('Suspicious activity detected.');

        $service->verifyCustomer('941639');
    }

    public function testZeroDisablesACheck(): void
    {
        $this->company->set('is_corporate', '1');
        $this->company->set(CompanySettingsEnum::VERIFY_MAX_ATTEMPTS->value, '0');

        $service = $this->service();

        // Six calls exceed every default; a disabled check also records no attempts at all.
        for ($i = 0; $i < 6; $i++) {
            $service->verifyCustomer(self::TAG);
        }

        $this->assertSame(0, RateLimiter::attempts("paso-rapido-verify:{$this->kanvasApp->getId()}:{$this->userId}"));
    }

    public function testNonNumericOverrideIsIgnoredInsteadOfLockingTheCompanyOut(): void
    {
        $this->company->set('is_corporate', '1');
        $this->company->set(CompanySettingsEnum::VERIFY_MAX_ATTEMPTS->value, 'unlimited');

        // A naive (int) cast would yield 0 and reject the very first request.
        $result = $this->service()->verifyCustomer(self::TAG);

        $this->assertSame(self::TAG, $result->device);
    }

    private function service(?Client $client = null): PasoRapidoService
    {
        return new PasoRapidoService(
            app: $this->kanvasApp,
            company: $this->company,
            config: [],
            client: $client ?? $this->apiClient(),
        );
    }

    private function apiClient(): Client
    {
        $client = Mockery::mock(Client::class);
        $client->shouldReceive('post')->andReturn([
            'nombreUsuario' => 'Test',
            'apellidoUsuario' => 'User',
            'dispositivo' => self::TAG,
            'descripcionMensaje' => 'ok',
            'rnc_Cedula' => '1234',
            'balance' => 100,
            'tipoDeReferencia' => 'tag',
            'referencia' => self::TAG,
            'cuenta' => '1234',
            'estado' => 'activo',
        ]);

        return $client;
    }

    private function neutralizeAppSetting(ConfigurationEnum $setting): void
    {
        $this->originalAppSettings[$setting->value] = $this->kanvasApp->get($setting->value);
        $this->kanvasApp->set($setting->value, '');
    }

    private function clearCounters(): void
    {
        $appId = $this->kanvasApp->getId();
        $ip = IPInfo::getClientIp();

        RateLimiter::clear("paso-rapido-verify:{$appId}:{$this->userId}");
        RateLimiter::clear("paso-rapido-verify-daily:{$appId}:{$this->userId}");
        RateLimiter::clear("paso-rapido-verify-ip-daily:{$appId}:{$ip}");
        Cache::forget("paso-rapido-verify-tags:{$appId}:{$this->userId}");
        Cache::forget("paso-rapido-ip-users:{$appId}:{$ip}");
    }
}
