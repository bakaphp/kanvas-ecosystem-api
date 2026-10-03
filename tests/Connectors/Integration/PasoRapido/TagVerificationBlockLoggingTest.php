<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\PasoRapido;

use Baka\Support\IPInfo;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Kanvas\Activities\Models\Activity;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\PasoRapido\Client;
use Kanvas\Connectors\PasoRapido\Enums\CompanySettingsEnum;
use Kanvas\Connectors\PasoRapido\Enums\ConfigurationEnum;
use Kanvas\Connectors\PasoRapido\Enums\TagVerificationBlockReasonEnum;
use Kanvas\Connectors\PasoRapido\Services\PasoRapidoService;
use Kanvas\Enums\AppEnums;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Users\Models\UsersAssociatedApps;
use Mockery;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Tests\TestCase;

final class TagVerificationBlockLoggingTest extends TestCase
{
    use DatabaseTransactions;

    private const TAG = '941001';
    private const BLOCKED_DESCRIPTION = 'PasoRapido tag verification blocked';
    private const SUCCESS_DESCRIPTION = 'PasoRapido tag verification';

    private Apps $kanvasApp;
    private Companies $company;
    private int $userId;
    private int $activityBaseline;
    private ?int $originalIsVerified = null;

    private array $originalAppSettings = [];

    protected function setUp(): void
    {
        parent::setUp();

        if (getenv('GITHUB_ACTIONS')) {
            $this->markTestSkipped('PasoRapido tests require external API credentials and are skipped in CI.');
        }

        $this->kanvasApp = app(Apps::class);
        $this->userId = auth()->user()->getId();
        $this->company = Companies::factory()->create(['users_id' => $this->userId]);
        $this->activityBaseline = (int) (Activity::max('id') ?? 0);

        $this->neutralizeAppSetting(ConfigurationEnum::VERIFY_TAG_ATTRIBUTE_SLUG);
        $this->neutralizeAppSetting(ConfigurationEnum::VERIFY_REQUIRE_VERIFIED_ACCOUNT);

        $this->clearCounters();
    }

    protected function tearDown(): void
    {
        if (! isset($this->company)) {
            parent::tearDown();

            return;
        }

        foreach ($this->originalAppSettings as $key => $value) {
            $value === null ? $this->kanvasApp->del($key) : $this->kanvasApp->set($key, $value);
        }

        if ($this->originalIsVerified !== null) {
            $this->restoreUserVerification();
        }

        $this->company->deleteAllSettings();
        $this->clearCounters();

        parent::tearDown();
    }

    public function testUnverifiedAccountWritesABlockedRowWithNullIsCorporate(): void
    {
        $this->kanvasApp->set(ConfigurationEnum::VERIFY_REQUIRE_VERIFIED_ACCOUNT->value, '1');
        $this->unverifyUser();

        try {
            $this->service()->verifyCustomer(self::TAG);
            $this->fail('Expected a ValidationException for an unverified account.');
        } catch (ValidationException $e) {
            $this->assertSame('Account not verified.', $e->getMessage());
        }

        $log = $this->assertSingleBlockedLog(TagVerificationBlockReasonEnum::UNVERIFIED_ACCOUNT);

        $this->assertSame($this->userId, $log->causer_id);
        $this->assertSame(self::TAG, $log->properties['tag']);
        $this->assertNull($log->properties['is_corporate']);
    }

    public function testTagNotOwnedWritesABlockedRowWithResolvedIsCorporate(): void
    {
        $this->kanvasApp->set(ConfigurationEnum::VERIFY_TAG_ATTRIBUTE_SLUG->value, 'paso-rapido-unowned-tag-slug');

        try {
            $this->service()->verifyCustomer(self::TAG);
            $this->fail('Expected a ValidationException for an unowned tag.');
        } catch (ValidationException $e) {
            $this->assertSame('Tag not associated with your account.', $e->getMessage());
        }

        $log = $this->assertSingleBlockedLog(TagVerificationBlockReasonEnum::TAG_NOT_OWNED);

        $this->assertSame($this->userId, $log->causer_id);
        $this->assertSame(self::TAG, $log->properties['tag']);
        $this->assertSame($this->kanvasApp->getId(), $log->properties['app_id']);
        $this->assertSame(IPInfo::getClientIp(), $log->properties['ip']);
        $this->assertFalse($log->properties['is_corporate']);
    }

    public function testCompanyBlockedWritesABlockedRowWithResolvedIsCorporate(): void
    {
        $this->company->set(CompanySettingsEnum::VERIFY_BLOCKED->value, '1');

        try {
            $this->service()->verifyCustomer(self::TAG);
            $this->fail('Expected a ValidationException for a blocked company.');
        } catch (ValidationException $e) {
            $this->assertSame('Tag verification is disabled for this company. Please contact support.', $e->getMessage());
        }

        $log = $this->assertSingleBlockedLog(TagVerificationBlockReasonEnum::COMPANY_BLOCKED);

        $this->assertSame(self::TAG, $log->properties['tag']);
        $this->assertFalse($log->properties['is_corporate']);
    }

    public function testIpMaxUsersWritesABlockedRowWithResolvedIsCorporate(): void
    {
        $this->company->set(CompanySettingsEnum::VERIFY_IP_MAX_USERS->value, '1');

        $appId = $this->kanvasApp->getId();
        $ip = IPInfo::getClientIp();
        Cache::put("paso-rapido-ip-users:{$appId}:{$ip}", [$this->userId, $this->userId + 1], 86400);

        try {
            $this->service()->verifyCustomer(self::TAG);
            $this->fail('Expected the IP user cap to trip.');
        } catch (TooManyRequestsHttpException $e) {
            $this->assertSame('Suspicious activity detected. Access temporarily restricted.', $e->getMessage());
        }

        $log = $this->assertSingleBlockedLog(TagVerificationBlockReasonEnum::IP_MAX_USERS);

        $this->assertFalse($log->properties['is_corporate']);
    }

    public function testUserMaxAttemptsWritesABlockedRowWithResolvedIsCorporate(): void
    {
        $this->company->set(CompanySettingsEnum::VERIFY_MAX_ATTEMPTS->value, '1');

        $service = $this->service();
        $service->verifyCustomer(self::TAG);

        try {
            $service->verifyCustomer(self::TAG);
            $this->fail('Expected the per-minute limit to trip.');
        } catch (TooManyRequestsHttpException $e) {
            $this->assertSame('Too many tag verification requests. Please try again later.', $e->getMessage());
        }

        $log = $this->assertSingleBlockedLog(TagVerificationBlockReasonEnum::USER_MAX_ATTEMPTS);

        $this->assertFalse($log->properties['is_corporate']);
    }

    public function testUserMaxDailyWritesABlockedRowWithResolvedIsCorporate(): void
    {
        $this->company->set(CompanySettingsEnum::VERIFY_MAX_DAILY->value, '1');

        $service = $this->service();
        $service->verifyCustomer(self::TAG);

        try {
            $service->verifyCustomer(self::TAG);
            $this->fail('Expected the daily limit to trip.');
        } catch (TooManyRequestsHttpException $e) {
            $this->assertSame('Daily tag verification limit reached.', $e->getMessage());
        }

        $log = $this->assertSingleBlockedLog(TagVerificationBlockReasonEnum::USER_MAX_DAILY);

        $this->assertFalse($log->properties['is_corporate']);
    }

    public function testIpMaxDailyWritesABlockedRow(): void
    {
        $this->company->set(CompanySettingsEnum::VERIFY_IP_MAX_DAILY->value, '1');

        $service = $this->service();
        $service->verifyCustomer(self::TAG);

        try {
            $service->verifyCustomer(self::TAG);
            $this->fail('Expected the IP daily limit to trip.');
        } catch (TooManyRequestsHttpException $e) {
            $this->assertSame('Too many requests from this network. Please try again later.', $e->getMessage());
        }

        $log = $this->assertSingleBlockedLog(TagVerificationBlockReasonEnum::IP_MAX_DAILY);

        $this->assertFalse($log->properties['is_corporate']);
    }

    public function testSequentialScanWritesABlockedRowWithIsCorporateTrue(): void
    {
        $this->company->set('is_corporate', '1');
        $this->company->set(CompanySettingsEnum::VERIFY_SEQUENTIAL_THRESHOLD->value, '3');

        $service = $this->service();
        $service->verifyCustomer('941637');
        $service->verifyCustomer('941638');

        try {
            $service->verifyCustomer('941639');
            $this->fail('Expected sequential scan detection to trip.');
        } catch (TooManyRequestsHttpException $e) {
            $this->assertSame('Suspicious activity detected. Access temporarily restricted.', $e->getMessage());
        }

        $log = $this->assertSingleBlockedLog(TagVerificationBlockReasonEnum::SEQUENTIAL_SCAN);

        $this->assertSame('941639', $log->properties['tag']);
        $this->assertTrue($log->properties['is_corporate']);
    }

    public function testIpAutoBlockWritesExactlyOneBlockedRowAcrossBothTriggerPoints(): void
    {
        $this->disableUserFacingGuards();

        $threshold = PasoRapidoService::VERIFY_ERROR_BLOCK_THRESHOLD;

        $client = Mockery::mock(Client::class);
        $client->shouldReceive('post')->times($threshold)->andThrow($this->upstreamForbidden());

        $service = $this->service($client);

        for ($i = 0; $i < $threshold; $i++) {
            try {
                $service->verifyCustomer(self::TAG);
            } catch (ValidationException) {
            }
        }

        try {
            $service->verifyCustomer(self::TAG);
            $this->fail('Expected the auto-blocked IP to be rejected before reaching the API.');
        } catch (TooManyRequestsHttpException $e) {
            $this->assertSame('Suspicious activity detected. Access temporarily restricted.', $e->getMessage());
        }

        $this->assertSingleBlockedLog(TagVerificationBlockReasonEnum::IP_AUTO_BLOCKED);
    }

    public function testRepeatedHitsOfTheSameLimitWithinTheHourWriteOnlyOneRow(): void
    {
        $this->company->set(CompanySettingsEnum::VERIFY_MAX_DAILY->value, '1');

        $service = $this->service();
        $service->verifyCustomer(self::TAG);

        for ($i = 0; $i < 3; $i++) {
            try {
                $service->verifyCustomer(self::TAG);
            } catch (TooManyRequestsHttpException) {
            }
        }

        $this->assertSingleBlockedLog(TagVerificationBlockReasonEnum::USER_MAX_DAILY);
    }

    public function testUpstream4xxWritesNoSuccessRow(): void
    {
        $this->disableUserFacingGuards();

        $client = Mockery::mock(Client::class);
        $client->shouldReceive('post')->once()->andThrow($this->upstreamForbidden());

        try {
            $this->service($client)->verifyCustomer(self::TAG);
            $this->fail('Expected a ValidationException from the upstream 403.');
        } catch (ValidationException) {
        }

        $this->assertCount(0, $this->successLogs());
    }

    public function testLoggingFailureDoesNotMaskTheOriginalException(): void
    {
        $this->company->set(CompanySettingsEnum::VERIFY_BLOCKED->value, '1');

        $realCache = Cache::getFacadeRoot();
        $cacheMock = Mockery::mock($realCache);
        $cacheMock->shouldReceive('add')->once()->andThrow(new RuntimeException('cache backend unavailable'));
        Cache::swap($cacheMock);

        try {
            try {
                $this->service()->verifyCustomer(self::TAG);
                $this->fail('Expected the original ValidationException to still surface.');
            } catch (ValidationException $e) {
                $this->assertSame('Tag verification is disabled for this company. Please contact support.', $e->getMessage());
            }
        } finally {
            Cache::swap($realCache);
        }

        $this->assertCount(0, $this->blockedLogsFor(TagVerificationBlockReasonEnum::COMPANY_BLOCKED));
    }

    public function testUpstreamSuccessWritesExactlyOneSuccessRowWithTheNewLogName(): void
    {
        $result = $this->service()->verifyCustomer(self::TAG);

        $this->assertSame(self::TAG, $result->device);

        $logs = $this->successLogs();
        $this->assertCount(1, $logs);

        $log = $logs->first();
        $this->assertSame($this->userId, $log->causer_id);
        $this->assertSame(self::TAG, $log->properties['tag']);
        $this->assertSame($this->kanvasApp->getId(), $log->properties['app_id']);
        $this->assertSame(IPInfo::getClientIp(), $log->properties['ip']);
    }

    private function assertSingleBlockedLog(TagVerificationBlockReasonEnum $reason): Activity
    {
        $logs = $this->blockedLogsFor($reason);

        $this->assertCount(1, $logs, "Expected exactly one blocked-verification row for reason {$reason->value}.");

        return $logs->first();
    }

    private function blockedLogsFor(TagVerificationBlockReasonEnum $reason): Collection
    {
        return $this->logsWithDescription(self::BLOCKED_DESCRIPTION)
            ->filter(fn (Activity $log): bool => $log->properties['reason'] === $reason->value)
            ->values();
    }

    private function successLogs(): Collection
    {
        return $this->logsWithDescription(self::SUCCESS_DESCRIPTION);
    }

    private function logsWithDescription(string $description): Collection
    {
        return Activity::where('id', '>', $this->activityBaseline)
            ->where('log_name', 'paso-rapido-verify-' . $this->kanvasApp->getId())
            ->where('description', $description)
            ->get();
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

    private function disableUserFacingGuards(): void
    {
        foreach ([
            CompanySettingsEnum::VERIFY_MAX_ATTEMPTS,
            CompanySettingsEnum::VERIFY_MAX_DAILY,
            CompanySettingsEnum::VERIFY_IP_MAX_DAILY,
            CompanySettingsEnum::VERIFY_IP_MAX_USERS,
            CompanySettingsEnum::VERIFY_SEQUENTIAL_THRESHOLD,
        ] as $setting) {
            $this->company->set($setting->value, '0');
        }
    }

    private function upstreamForbidden(): ClientException
    {
        return new ClientException(
            'Client error',
            new Request('POST', ConfigurationEnum::VERIFY_PATH->value),
            new Response(403, [], (string) json_encode([
                'codigoMensaje' => 403,
                'descripcionMensaje' => 'Dispositivo inválido (Estado: Inhabilitado).',
            ])),
        );
    }

    private function neutralizeAppSetting(ConfigurationEnum $setting): void
    {
        $this->originalAppSettings[$setting->value] = $this->kanvasApp->get($setting->value);
        $this->kanvasApp->set($setting->value, '');
    }

    private function unverifyUser(): void
    {
        $profile = $this->userProfile();
        $this->originalIsVerified = (int) $profile->is_verified;
        $profile->is_verified = 0;
        $profile->saveOrFail();
    }

    private function restoreUserVerification(): void
    {
        $profile = $this->userProfile();
        $profile->is_verified = $this->originalIsVerified;
        $profile->saveOrFail();
    }

    private function userProfile(): UsersAssociatedApps
    {
        return UsersAssociatedApps::fromApp($this->kanvasApp)
            ->where('users_id', $this->userId)
            ->where('companies_id', AppEnums::GLOBAL_COMPANY_ID->getValue())
            ->firstOrFail();
    }

    private function clearCounters(): void
    {
        $appId = $this->kanvasApp->getId();
        $ip = IPInfo::getClientIp();

        RateLimiter::clear("paso-rapido-verify:{$appId}:{$this->userId}");
        RateLimiter::clear("paso-rapido-verify-daily:{$appId}:{$this->userId}");
        RateLimiter::clear("paso-rapido-verify-ip-daily:{$appId}:{$ip}");
        RateLimiter::clear("paso-rapido-verify-fail:{$appId}:{$ip}");
        Cache::forget("paso-rapido-verify-tags:{$appId}:{$this->userId}");
        Cache::forget("paso-rapido-ip-users:{$appId}:{$ip}");
        Cache::forget("paso-rapido-verify-blocked-ip:{$appId}:{$ip}");

        foreach (TagVerificationBlockReasonEnum::cases() as $reason) {
            Cache::forget("paso-rapido-verify-blocked-log:{$appId}:{$reason->value}:{$this->userId}");
            Cache::forget("paso-rapido-verify-blocked-log:{$appId}:{$reason->value}:{$ip}");
        }
    }
}
