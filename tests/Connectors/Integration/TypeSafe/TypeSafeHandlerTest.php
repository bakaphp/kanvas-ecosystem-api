<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\TypeSafe;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\TypeSafe\Enums\ConfigurationEnum;
use Kanvas\Connectors\TypeSafe\Handlers\TypeSafeHandler;
use Kanvas\Connectors\TypeSafe\Services\TypeSafeConfigService;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Regions\Models\Regions;
use Tests\TestCase;

class TypeSafeHandlerTest extends TestCase
{
    use DatabaseTransactions;

    /** @var array<string, mixed> */
    private array $originalSettings = [];

    protected function setUp(): void
    {
        parent::setUp();

        foreach (ConfigurationEnum::cases() as $setting) {
            $this->originalSettings[$setting->value] = app(Apps::class)->get($setting->value);
        }

        foreach (ConfigurationEnum::cases() as $setting) {
            app(Apps::class)->set($setting->value, '');
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->originalSettings as $key => $value) {
            app(Apps::class)->set($key, $value ?? '');
        }

        parent::tearDown();
    }

    public function test_setup_stores_a_key_the_api_accepts(): void
    {
        Http::fake(['api.typesafe.ai/v1/models' => Http::response(['models' => []], 200)]);

        $this->assertTrue($this->handler(['api_key' => '  typesafe-live-key  '])->setup());

        $config = new TypeSafeConfigService(app(Apps::class));

        $this->assertSame('typesafe-live-key', $config->apiKey());
        $this->assertTrue($config->isConfigured());
    }

    public function test_setup_rejects_a_key_the_api_refuses_and_stores_nothing(): void
    {
        Http::fake(['api.typesafe.ai/v1/models' => Http::response(['message' => 'invalid key'], 401)]);

        try {
            $this->handler(['api_key' => 'nope'])->setup();
            $this->fail('Expected a ValidationException.');
        } catch (ValidationException) {
            $this->addToAssertionCount(1);
        }

        $this->assertFalse(new TypeSafeConfigService(app(Apps::class))->isConfigured());
    }

    public function test_setup_requires_a_key(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('TypeSafe API key is required.');

        $this->handler([])->setup();
    }

    /**
     * Rotating the key leaves the form's model field blank. Writing the default there would move every
     * threshold the app has tuned onto a different calibration.
     */
    public function test_rotating_the_key_leaves_an_existing_model_override_alone(): void
    {
        Http::fake(['api.typesafe.ai/v1/models' => Http::response(['models' => []], 200)]);

        app(Apps::class)->set(ConfigurationEnum::TYPESAFE_MODEL->value, 'jev-preview');

        $this->handler(['api_key' => 'rotated-key'])->setup();

        $this->assertSame('jev-preview', new TypeSafeConfigService(app(Apps::class))->model());
    }

    public function test_an_app_that_never_set_a_model_reads_the_pinned_default(): void
    {
        Http::fake(['api.typesafe.ai/v1/models' => Http::response(['models' => []], 200)]);

        $this->handler(['api_key' => 'first-key'])->setup();

        $this->assertSame(
            TypeSafeConfigService::DEFAULT_MODEL,
            new TypeSafeConfigService(app(Apps::class))->model(),
        );
    }

    /**
     * @param array<string, mixed> $data
     */
    private function handler(array $data): TypeSafeHandler
    {
        $app = app(Apps::class);
        $company = Companies::first();

        return new TypeSafeHandler(
            $app,
            $company,
            Regions::getDefault($company, $app),
            $data,
        );
    }
}
