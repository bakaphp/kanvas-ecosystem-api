<?php

declare(strict_types=1);

namespace Tests\Communication\Channels\WhatsApp\Providers\Meta;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Kanvas\Apps\Models\Apps;
use Kanvas\Communication\Channels\WhatsApp\Providers\Meta\Enums\ConfigurationEnum;
use Kanvas\Communication\Channels\WhatsApp\Providers\Meta\Handlers\MetaWhatsAppHandler;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Regions\Models\Regions;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\Models\Integrations;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Tests\Communication\Concerns\RefusesParallelRuns;
use Tests\TestCase;

final class MetaWhatsAppHandlerTest extends TestCase
{
    use DatabaseTransactions;
    use RefusesParallelRuns;

    private const string PHONE_NUMBER_ID = '106540352242922';
    private const string WABA_ID = '102290129340398';

    protected function tearDown(): void
    {
        // Company settings live in Redis too, which no transaction rolls back.
        $this->clearSettings();

        parent::tearDown();
    }

    public function testSetupStoresTheNumberAndTheEncryptedTokenOnTheCompany(): void
    {
        $this->fakeMeta();
        $handler = $this->handler([
            'phone_number_id' => self::PHONE_NUMBER_ID,
            'access_token' => 'test-token',
            'waba_id' => self::WABA_ID,
        ]);

        $this->assertTrue($handler->setup());

        $company = $handler->company;
        Http::assertSent(fn (Request $request): bool => $request->hasHeader('Authorization', 'Bearer test-token'));
        $this->assertSame(self::PHONE_NUMBER_ID, (string) $company->get(ConfigurationEnum::PHONE_NUMBER_ID->value));
        $this->assertSame('test-token', $company->get(ConfigurationEnum::ACCESS_TOKEN->value));
        $this->assertTrue($company->isSecret(ConfigurationEnum::ACCESS_TOKEN->value));
        $this->assertSame(self::WABA_ID, (string) $company->get(ConfigurationEnum::WABA_ID->value));
        $this->assertSame('+1 809-555-0100', $company->get(ConfigurationEnum::DISPLAY_PHONE_NUMBER->value));
        $this->assertSame([], $handler->persistedConfig(), 'the integration row only records that it was completed');
    }

    public function testATokenMetaRejectsStoresNothing(): void
    {
        Http::fake([
            'graph.facebook.com/*' => Http::response(['error' => ['message' => 'Invalid OAuth access token.']], 401),
        ]);
        $handler = $this->handler([
            'phone_number_id' => self::PHONE_NUMBER_ID,
            'access_token' => 'bad-token',
            'waba_id' => self::WABA_ID,
        ]);

        try {
            $handler->setup();
            $this->fail('A rejected token must not set the integration up.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('Invalid OAuth access token.', $exception->getMessage());
        }

        $this->assertNull($handler->company->get(ConfigurationEnum::PHONE_NUMBER_ID->value));
        $this->assertNull($handler->company->get(ConfigurationEnum::ACCESS_TOKEN->value));
    }

    /**
     * The app secret is ours, an app setting, so the three of them are all a company hands over.
     */
    #[DataProvider('missingField')]
    public function testTheNumberItsWabaAndTheTokenAreAllRequired(string $missing): void
    {
        Http::fake();
        $data = ['phone_number_id' => self::PHONE_NUMBER_ID, 'access_token' => 'test-token', 'waba_id' => self::WABA_ID];
        unset($data[$missing]);

        $this->expectException(ValidationException::class);

        try {
            $this->handler($data)->setup();
        } finally {
            Http::assertNothingSent();
        }
    }

    public static function missingField(): array
    {
        return [
            'phone number id' => ['phone_number_id'],
            'access token' => ['access_token'],
            'waba id' => ['waba_id'],
        ];
    }

    /**
     * Serial: it sets the test app's Meta id and secret, which every process shares.
     */
    #[Group('serial')]
    public function testAnEmbeddedSignupsCodeIsExchangedForTheBusinessToken(): void
    {
        $this->withAppMeta(['meta-app-id', 'meta-app-secret'], function (): void {
            Http::fake([
                'graph.facebook.com/*/oauth/access_token*' => Http::response(['access_token' => 'business-token', 'token_type' => 'bearer']),
                'graph.facebook.com/*/' . self::PHONE_NUMBER_ID . '*' => Http::response(['id' => self::PHONE_NUMBER_ID, 'display_phone_number' => '+1 809-555-0100']),
            ]);
            $handler = $this->handler(['phone_number_id' => self::PHONE_NUMBER_ID, 'waba_id' => self::WABA_ID, 'code' => 'signup-code']);

            $handler->setup();

            Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/oauth/access_token')
                && $request['client_id'] === 'meta-app-id'
                && $request['client_secret'] === 'meta-app-secret'
                && $request['code'] === 'signup-code');
            Http::assertSent(fn (Request $request): bool => $request->hasHeader('Authorization', 'Bearer business-token'));
            $this->assertSame('business-token', $handler->company->get(ConfigurationEnum::ACCESS_TOKEN->value));
        });
    }

    #[Group('serial')]
    public function testACodeIsRefusedWhileTheAppHasNoMetaId(): void
    {
        $this->withAppMeta([null, 'meta-app-secret'], function (): void {
            Http::fake();

            try {
                $this->handler(['phone_number_id' => self::PHONE_NUMBER_ID, 'waba_id' => self::WABA_ID, 'code' => 'signup-code'])->setup();
                $this->fail('A code cannot be exchanged without the app id.');
            } catch (ValidationException $exception) {
                $this->assertStringContainsString(ConfigurationEnum::APP_ID->value, $exception->getMessage());
            }

            Http::assertNothingSent();
        });
    }

    public function testTheFormAsksForWhatMetaHandsAPartnerAndNoSecret(): void
    {
        $integration = Integrations::query()
            ->where('name', IntegrationsEnum::META_WHATSAPP->value)
            ->where('apps_id', 0)
            ->firstOrFail();

        $this->assertSame(MetaWhatsAppHandler::class, $integration->handler);
        $this->assertEqualsCanonicalizing(['phone_number_id', 'waba_id', 'access_token'], array_keys($integration->config));
    }

    public function testANonNumericPhoneNumberIdNeverReachesMeta(): void
    {
        Http::fake();

        $this->expectException(ValidationException::class);

        try {
            $this->handler(['phone_number_id' => '../me/accounts', 'access_token' => 'test-token', 'waba_id' => self::WABA_ID])->setup();
        } finally {
            Http::assertNothingSent();
        }
    }

    public function testTeardownRemovesEverySetting(): void
    {
        $this->fakeMeta();
        $handler = $this->handler([
            'phone_number_id' => self::PHONE_NUMBER_ID,
            'access_token' => 'test-token',
            'waba_id' => self::WABA_ID,
        ]);
        $handler->setup();

        $handler->teardown();

        foreach ([ConfigurationEnum::PHONE_NUMBER_ID, ConfigurationEnum::ACCESS_TOKEN, ConfigurationEnum::WABA_ID, ConfigurationEnum::DISPLAY_PHONE_NUMBER] as $key) {
            $this->assertNull($handler->company->get($key->value), $key->value . ' outlived the disconnect');
        }
    }

    private function fakeMeta(): void
    {
        Http::fake([
            'graph.facebook.com/*/' . self::PHONE_NUMBER_ID . '*' => Http::response([
                'id' => self::PHONE_NUMBER_ID,
                'display_phone_number' => '+1 809-555-0100',
                'verified_name' => 'Kanvas Motors',
                'quality_rating' => 'GREEN',
            ]),
        ]);
    }

    private function handler(array $data): MetaWhatsAppHandler
    {
        $app = app(Apps::class);
        $company = auth()->user()->getCurrentCompany();

        return new MetaWhatsAppHandler(
            $app,
            $company,
            Regions::getDefault($company, $app),
            $data
        );
    }

    /**
     * The test app is the one a local WhatsApp integration runs on, so its Meta id and secret are
     * put back as they were.
     *
     * @param array{0: ?string, 1: ?string} $meta app id and secret
     */
    private function withAppMeta(array $meta, callable $test): void
    {
        $this->refuseParallelRun();
        $app = app(Apps::class);
        $keys = [ConfigurationEnum::APP_ID->value, ConfigurationEnum::APP_SECRET->value];
        $before = [];

        foreach ($keys as $i => $key) {
            $before[$key] = ['value' => $app->get($key), 'secret' => $app->isSecret($key)];
            if ($meta[$i] === null) {
                $app->del($key);
            } else {
                $app->set($key, $meta[$i]);
            }
        }

        try {
            $test();
        } finally {
            foreach ($before as $key => $setting) {
                match (true) {
                    $setting['value'] === null => $app->del($key),
                    $setting['secret'] => $app->setEncrypted($key, $setting['value']),
                    default => $app->set($key, $setting['value']),
                };
            }
        }
    }

    private function clearSettings(): void
    {
        $company = auth()->user()->getCurrentCompany();

        foreach (ConfigurationEnum::cases() as $key) {
            $company->del($key->value);
        }
    }
}
