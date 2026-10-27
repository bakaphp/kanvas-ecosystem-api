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
use Tests\TestCase;

final class MetaWhatsAppHandlerTest extends TestCase
{
    use DatabaseTransactions;

    private const string PHONE_NUMBER_ID = '106540352242922';

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
            'waba_id' => '102290129340398',
        ]);

        $this->assertTrue($handler->setup());

        $company = $handler->company;
        Http::assertSent(fn (Request $request): bool => $request->hasHeader('Authorization', 'Bearer test-token'));
        $this->assertSame(self::PHONE_NUMBER_ID, (string) $company->get(ConfigurationEnum::PHONE_NUMBER_ID->value));
        $this->assertSame('test-token', $company->get(ConfigurationEnum::ACCESS_TOKEN->value));
        $this->assertTrue($company->isSecret(ConfigurationEnum::ACCESS_TOKEN->value));
        $this->assertSame('102290129340398', (string) $company->get(ConfigurationEnum::WABA_ID->value));
        $this->assertSame('+1 809-555-0100', $company->get(ConfigurationEnum::DISPLAY_PHONE_NUMBER->value));
        $this->assertSame([], $handler->persistedConfig(), 'the integration row only records that it was completed');
    }

    public function testResavingWithoutAWabaClearsTheOldOne(): void
    {
        $this->fakeMeta();
        $this->handler(['phone_number_id' => self::PHONE_NUMBER_ID, 'access_token' => 'test-token', 'waba_id' => '1'])->setup();

        $handler = $this->handler(['phone_number_id' => self::PHONE_NUMBER_ID, 'access_token' => 'test-token']);
        $handler->setup();

        $this->assertNull($handler->company->get(ConfigurationEnum::WABA_ID->value));
    }

    public function testATokenMetaRejectsStoresNothing(): void
    {
        Http::fake([
            'graph.facebook.com/*' => Http::response(['error' => ['message' => 'Invalid OAuth access token.']], 401),
        ]);
        $handler = $this->handler(['phone_number_id' => self::PHONE_NUMBER_ID, 'access_token' => 'bad-token']);

        try {
            $handler->setup();
            $this->fail('A rejected token must not set the integration up.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('Invalid OAuth access token.', $exception->getMessage());
        }

        $this->assertNull($handler->company->get(ConfigurationEnum::PHONE_NUMBER_ID->value));
        $this->assertNull($handler->company->get(ConfigurationEnum::ACCESS_TOKEN->value));
    }

    public function testBothFieldsAreRequired(): void
    {
        Http::fake();

        $this->expectException(ValidationException::class);

        try {
            $this->handler(['phone_number_id' => self::PHONE_NUMBER_ID])->setup();
        } finally {
            Http::assertNothingSent();
        }
    }

    public function testANonNumericPhoneNumberIdNeverReachesMeta(): void
    {
        Http::fake();

        $this->expectException(ValidationException::class);

        try {
            $this->handler(['phone_number_id' => '../me/accounts', 'access_token' => 'test-token'])->setup();
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
            'waba_id' => '102290129340398',
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

    private function clearSettings(): void
    {
        $company = auth()->user()->getCurrentCompany();

        foreach (ConfigurationEnum::cases() as $key) {
            $company->del($key->value);
        }
    }
}
