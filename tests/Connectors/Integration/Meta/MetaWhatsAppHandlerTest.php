<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Meta;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Kanvas\Apps\Models\Apps;
use Kanvas\Communication\Connections\DataTransferObject\AccountSelection;
use Kanvas\Connectors\Meta\Client;
use Kanvas\Connectors\Meta\Enums\ConfigurationEnum;
use Kanvas\Connectors\Meta\Handlers\MetaWhatsAppHandler;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Regions\Models\Regions;
use Kanvas\Social\Messages\DataTransferObject\BurstPolicy;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\Enums\StatusEnum;
use Kanvas\Workflow\Integrations\Models\IntegrationsCompany;
use Kanvas\Workflow\Integrations\Models\Status;
use Kanvas\Workflow\Models\Integrations;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Tests\Communication\Concerns\RefusesParallelRuns;
use Tests\TestCase;

final class MetaWhatsAppHandlerTest extends TestCase
{
    use DatabaseTransactions;
    use RefusesParallelRuns;

    protected $connectionsToTransact = [null, 'workflow'];

    private const string PHONE_NUMBER_ID = '106540352242922';
    private const string SECOND_PHONE_NUMBER_ID = '106540352242933';
    private const string WABA_ID = '102290129340398';

    protected function setUp(): void
    {
        parent::setUp();

        // A test that fakes too little fails here instead of asking Meta.
        Http::preventStrayRequests();
    }

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
        $this->assertSame(
            ['phone_numbers' => [['phone_number_id' => self::PHONE_NUMBER_ID, 'display_phone_number' => '+1 809-555-0100']]],
            $handler->persistedConfig(),
            'the integration row keeps the numbers, never the credentials'
        );
    }

    public function testAnotherNumberIsConnectedBesideTheFirst(): void
    {
        $this->fakeMeta();
        $this->integrate(['phone_number_id' => self::PHONE_NUMBER_ID, 'access_token' => 'test-token', 'waba_id' => self::WABA_ID]);

        $handler = $this->integrate(['phone_number_id' => self::SECOND_PHONE_NUMBER_ID, 'access_token' => 'test-token', 'waba_id' => self::WABA_ID]);

        $this->assertSame(
            [self::PHONE_NUMBER_ID, self::SECOND_PHONE_NUMBER_ID],
            array_column($handler->persistedConfig()['phone_numbers'], 'phone_number_id')
        );
        $this->assertSame(
            self::PHONE_NUMBER_ID,
            (string) $handler->company->get(ConfigurationEnum::PHONE_NUMBER_ID->value),
            'the first number stays the company\'s default'
        );
        $accounts = $this->accounts();
        $this->assertSame(['+18095550100', '+18095550200'], array_column($accounts, 'key'));
        $this->assertSame([true, false], array_column($accounts, 'isDefault'));
    }

    public function testACompanyAlreadyConnectedAddsANumberWithTheTokenItHas(): void
    {
        $this->fakeMeta();
        $this->integrate(['phone_number_id' => self::PHONE_NUMBER_ID, 'access_token' => 'test-token', 'waba_id' => self::WABA_ID]);

        $handler = $this->integrate(['phone_number_id' => self::SECOND_PHONE_NUMBER_ID, 'waba_id' => self::WABA_ID]);

        $this->assertCount(2, $handler->persistedConfig()['phone_numbers']);
        $this->assertSame('test-token', $handler->company->get(ConfigurationEnum::ACCESS_TOKEN->value));
    }

    /**
     * Production today: the number is in the company's settings and the row keeps nothing.
     */
    public function testACompanySetUpWithOneNumberKeepsItWhenItConnectsASecond(): void
    {
        $this->fakeMeta();
        $company = auth()->user()->getCurrentCompany();
        $company->set(ConfigurationEnum::PHONE_NUMBER_ID->value, self::PHONE_NUMBER_ID);
        $company->set(ConfigurationEnum::DISPLAY_PHONE_NUMBER->value, '+1 809-555-0100');
        $company->set(ConfigurationEnum::WABA_ID->value, self::WABA_ID);
        $company->setEncrypted(ConfigurationEnum::ACCESS_TOKEN->value, 'test-token');
        $this->integrationRow(null);

        $accounts = $this->accounts();
        $this->assertCount(1, $accounts);
        $this->assertSame('+18095550100', $accounts[0]->key);
        $this->assertTrue($accounts[0]->isDefault);
        $this->assertSame(
            ['phone_number_id' => self::PHONE_NUMBER_ID, 'waba_id' => self::WABA_ID],
            $accounts[0]->attributes,
            'the connection it already has must not read as changed'
        );

        $handler = $this->integrate(['phone_number_id' => self::SECOND_PHONE_NUMBER_ID, 'code' => null, 'waba_id' => self::WABA_ID]);

        $this->assertSame(
            [self::PHONE_NUMBER_ID, self::SECOND_PHONE_NUMBER_ID],
            array_column($handler->persistedConfig()['phone_numbers'], 'phone_number_id')
        );
        $this->assertSame(self::PHONE_NUMBER_ID, (string) $company->get(ConfigurationEnum::PHONE_NUMBER_ID->value));
    }

    public function testAListOfNumbersIsTheWholeSelection(): void
    {
        $this->fakeMeta();
        $this->integrate(['phone_number_id' => self::PHONE_NUMBER_ID, 'access_token' => 'test-token', 'waba_id' => self::WABA_ID]);

        $handler = $this->integrate([
            'phone_number_id' => self::SECOND_PHONE_NUMBER_ID,
            'phone_number_ids' => [self::SECOND_PHONE_NUMBER_ID],
            'waba_id' => self::WABA_ID,
        ]);

        $this->assertSame([self::SECOND_PHONE_NUMBER_ID], array_column($handler->persistedConfig()['phone_numbers'], 'phone_number_id'));
        $this->assertSame(
            self::SECOND_PHONE_NUMBER_ID,
            (string) $handler->company->get(ConfigurationEnum::PHONE_NUMBER_ID->value),
            'the default moves once the company gives its first number up'
        );
        $this->assertSame('+1 809-555-0200', $handler->company->get(ConfigurationEnum::DISPLAY_PHONE_NUMBER->value));
    }

    /**
     * A form sends its untouched optional field as null or an empty list.
     */
    #[DataProvider('unsentSelection')]
    public function testAnEmptySelectionAddsTheNumberInsteadOfReplacingTheOthers(mixed $phoneNumberIds): void
    {
        $this->fakeMeta();
        $this->integrate(['phone_number_id' => self::PHONE_NUMBER_ID, 'access_token' => 'test-token', 'waba_id' => self::WABA_ID]);

        $handler = $this->integrate([
            'phone_number_id' => self::SECOND_PHONE_NUMBER_ID,
            'phone_number_ids' => $phoneNumberIds,
            'waba_id' => self::WABA_ID,
        ]);

        $this->assertSame(
            [self::PHONE_NUMBER_ID, self::SECOND_PHONE_NUMBER_ID],
            array_column($handler->persistedConfig()['phone_numbers'], 'phone_number_id')
        );
    }

    public static function unsentSelection(): array
    {
        return [
            'null' => [null],
            'an empty list' => [[]],
        ];
    }

    public function testANumberOfAnotherWabaIsNotConnected(): void
    {
        $this->fakeMeta();

        try {
            $this->handler(['phone_number_id' => '999000111222333', 'access_token' => 'test-token', 'waba_id' => self::WABA_ID])->setup();
            $this->fail('A number the WABA does not have cannot be connected.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('999000111222333 is not in WhatsApp Business Account', $exception->getMessage());
        }

        $this->assertNull(auth()->user()->getCurrentCompany()->get(ConfigurationEnum::ACCESS_TOKEN->value));
    }

    public function testASecondWabaIsRefusedWhileTheFirstIsConnected(): void
    {
        $this->fakeMeta();
        $this->integrate(['phone_number_id' => self::PHONE_NUMBER_ID, 'access_token' => 'test-token', 'waba_id' => self::WABA_ID]);
        Http::fake();

        try {
            $this->handler(['phone_number_id' => self::SECOND_PHONE_NUMBER_ID, 'access_token' => 'test-token', 'waba_id' => '555000111222333'])->setup();
            $this->fail('One integration is one WhatsApp Business Account.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('Disconnect the integration', $exception->getMessage());
        }

        $this->assertSame(self::WABA_ID, (string) auth()->user()->getCurrentCompany()->get(ConfigurationEnum::WABA_ID->value));
    }

    public function testTheTokenACompanyHasIsNeverTriedOnAnotherWaba(): void
    {
        $this->fakeMeta();
        $this->integrate(['phone_number_id' => self::PHONE_NUMBER_ID, 'access_token' => 'test-token', 'waba_id' => self::WABA_ID]);
        Http::fake();

        try {
            $this->handler(['phone_number_id' => self::SECOND_PHONE_NUMBER_ID, 'waba_id' => '555000111222333'])->setup();
            $this->fail('A number of another account cannot ride on the token of the one connected.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('Disconnect the integration', $exception->getMessage());
        }

        Http::assertNothingSent();
    }

    public function testAnUnreachableMetaIsAValidationErrorThatNamesNoRequest(): void
    {
        Http::fake(fn (Request $request) => throw new ConnectionException('cURL error 28: timed out for ' . $request->url()));

        try {
            $this->handler(['phone_number_id' => self::PHONE_NUMBER_ID, 'access_token' => 'test-token', 'waba_id' => self::WABA_ID])->setup();
            $this->fail('An unreachable Meta cannot connect a number.');
        } catch (ValidationException $exception) {
            $this->assertStringNotContainsString('graph.facebook.com', $exception->getMessage());
            $this->assertStringNotContainsString(self::WABA_ID, $exception->getMessage());
        }

        $this->assertNull(auth()->user()->getCurrentCompany()->get(ConfigurationEnum::ACCESS_TOKEN->value));
    }

    public function testTheWabasNumbersAreReadPageByPage(): void
    {
        Http::preventStrayRequests();
        Http::fake(fn (Request $request) => Http::response(
            isset($request['after'])
                ? ['data' => [['id' => self::SECOND_PHONE_NUMBER_ID, 'display_phone_number' => '+1 809-555-0200']]]
                : [
                    'data' => [['id' => self::PHONE_NUMBER_ID, 'display_phone_number' => '+1 809-555-0100']],
                    'paging' => ['cursors' => ['after' => 'next-page'], 'next' => 'https://graph.facebook.com/next'],
                ]
        ));

        $numbers = new Client('test-token')->phoneNumbers(self::WABA_ID);

        $this->assertSame([self::PHONE_NUMBER_ID, self::SECOND_PHONE_NUMBER_ID], array_column($numbers, 'id'));
        Http::assertSentCount(2);
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
                'graph.facebook.com/*/' . self::WABA_ID . '/phone_numbers*' => Http::response(['data' => [
                    ['id' => self::PHONE_NUMBER_ID, 'display_phone_number' => '+1 809-555-0100'],
                ]]),
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

    #[Group('serial')]
    public function testAnUnreachableMetaNeverPutsTheAppSecretInTheError(): void
    {
        $this->withAppMeta(['meta-app-id', 'meta-app-secret'], function (): void {
            Http::fake(fn (Request $request) => throw new ConnectionException(
                'cURL error 28: timed out for ' . $request->url()
            ));

            try {
                $this->handler(['phone_number_id' => self::PHONE_NUMBER_ID, 'waba_id' => self::WABA_ID, 'code' => 'signup-code'])->setup();
                $this->fail('An unreachable Meta cannot finish a signup.');
            } catch (ValidationException $exception) {
                $this->assertStringNotContainsString('meta-app-secret', $exception->getMessage());
                $this->assertStringNotContainsString('signup-code', $exception->getMessage());
            }
        });
    }

    #[Group('serial')]
    public function testACodeMetaAnswersWithoutATokenIsRefusedBeforeTheNumberIsRead(): void
    {
        $this->withAppMeta(['meta-app-id', 'meta-app-secret'], function (): void {
            Http::fake(['graph.facebook.com/*/oauth/access_token*' => Http::response(['token_type' => 'bearer'])]);

            try {
                $this->handler(['phone_number_id' => self::PHONE_NUMBER_ID, 'waba_id' => self::WABA_ID, 'code' => 'signup-code'])->setup();
                $this->fail('A signup without a token cannot be saved.');
            } catch (ValidationException $exception) {
                $this->assertStringContainsString('without a business token', $exception->getMessage());
            }

            Http::assertSentCount(1);
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

    public function testInboundWhatsAppIsAnsweredWithoutWaiting(): void
    {
        $this->fakeMeta();
        $handler = $this->handler([
            'phone_number_id' => self::PHONE_NUMBER_ID,
            'access_token' => 'test-token',
            'waba_id' => self::WABA_ID,
        ]);

        $handler->setup();

        $policy = BurstPolicy::fromCompanySettings($handler->company, 'meta_whatsapp', []);
        $this->assertSame(0, $policy->closeIdleSeconds);
        $this->assertSame(0, $policy->jitterSeconds);
    }

    public function testABurstWindowTheCompanyChoseIsLeftAlone(): void
    {
        $this->fakeMeta();
        $handler = $this->handler([
            'phone_number_id' => self::PHONE_NUMBER_ID,
            'access_token' => 'test-token',
            'waba_id' => self::WABA_ID,
        ]);
        $handler->company->set(ConfigurationEnum::BURST_IDLE_SECONDS->value, 45);

        $handler->setup();

        $this->assertSame(45, (int) $handler->company->get(ConfigurationEnum::BURST_IDLE_SECONDS->value));
        $this->assertSame(0, (int) $handler->company->get(ConfigurationEnum::BURST_JITTER_SECONDS->value));
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

    /**
     * The WABA holds two numbers. Anything else the code asks Meta for fails the test rather than
     * leave the building.
     */
    private function fakeMeta(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'graph.facebook.com/*/' . self::WABA_ID . '/phone_numbers*' => Http::response(['data' => [
                [
                    'id' => self::PHONE_NUMBER_ID,
                    'display_phone_number' => '+1 809-555-0100',
                    'verified_name' => 'Kanvas Motors',
                    'quality_rating' => 'GREEN',
                ],
                [
                    'id' => self::SECOND_PHONE_NUMBER_ID,
                    'display_phone_number' => '+1 809-555-0200',
                    'verified_name' => 'Kanvas Motors Service',
                    'quality_rating' => 'GREEN',
                ],
            ]]),
        ]);
    }

    /**
     * A save as the integration mutation does it: setup(), then what the handler keeps on the row.
     */
    private function integrate(array $data): MetaWhatsAppHandler
    {
        $handler = $this->handler($data);
        $handler->setup();
        $this->integrationRow($handler->persistedConfig() ?: null);

        return $handler;
    }

    private function integrationRow(?array $config): IntegrationsCompany
    {
        $company = auth()->user()->getCurrentCompany();
        $app = app(Apps::class);

        $integrationCompany = IntegrationsCompany::query()->firstOrNew([
            'apps_id' => $app->getId(),
            'companies_id' => $company->getId(),
            'integrations_id' => Integrations::query()
                ->where('name', IntegrationsEnum::META_WHATSAPP->value)
                ->where('apps_id', 0)
                ->firstOrFail()
                ->getId(),
            'region_id' => Regions::getDefault($company, $app)->getId(),
        ]);
        $integrationCompany->status_id = Status::query()
            ->where('slug', StatusEnum::ACTIVE->value)
            ->where('apps_id', 0)
            ->firstOrFail()
            ->getId();
        $integrationCompany->config = $config;
        $integrationCompany->is_deleted = 0;
        $integrationCompany->saveOrFail();

        return $integrationCompany;
    }

    /**
     * @return list<AccountSelection>
     */
    private function accounts(): array
    {
        return MetaWhatsAppHandler::accounts(
            IntegrationsCompany::query()
                ->fromApp(app(Apps::class))
                ->fromCompany(auth()->user()->getCurrentCompany())
                ->whereHas('integration', fn ($query) => $query->where('name', IntegrationsEnum::META_WHATSAPP->value))
                ->firstOrFail()
        );
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
