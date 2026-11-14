<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Mailgun;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Mailgun\Enums\ConfigurationEnum;
use Kanvas\Connectors\Mailgun\Handlers\MailgunHandler;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Regions\Models\Regions;
use Tests\TestCase;

final class MailgunHandlerTest extends TestCase
{
    use DatabaseTransactions;

    protected array $connectionsToTransact = ['mysql'];

    protected function tearDown(): void
    {
        // App-level config also writes to cache (not rolled back by the transaction).
        // Leaving MAILGUN_API_KEY set would make the Apollo no-data fallback fire in
        // unrelated tests, so clear it.
        app(Apps::class)->del(ConfigurationEnum::API_KEY->value);

        parent::tearDown();
    }

    public function test_setup_stores_api_key_at_app_level_and_webhook_key_at_company_level(): void
    {
        $app = app(Apps::class);
        $company = static::$cachedUser->getCurrentCompany();
        $region = Regions::getDefault($company, $app);

        $apiKey = 'mg-' . uniqid();
        $webhookKey = 'wsk-' . uniqid();

        $handler = new MailgunHandler($app, $company, $region, [
            'webhook_signing_key' => $webhookKey,
            'api_key' => $apiKey,
        ]);

        $this->assertTrue($handler->setup());
        $this->assertSame($apiKey, $app->get(ConfigurationEnum::API_KEY->value));
        $this->assertSame($webhookKey, $company->get(ConfigurationEnum::WEBHOOK_SIGNING_KEY->value));
    }

    public function test_setup_succeeds_without_an_api_key(): void
    {
        $app = app(Apps::class);
        $company = static::$cachedUser->getCurrentCompany();
        $region = Regions::getDefault($company, $app);

        $handler = new MailgunHandler($app, $company, $region, [
            'webhook_signing_key' => 'wsk-' . uniqid(),
        ]);

        $this->assertTrue($handler->setup());
    }

    public function test_setup_requires_the_webhook_signing_key(): void
    {
        $app = app(Apps::class);
        $company = static::$cachedUser->getCurrentCompany();
        $region = Regions::getDefault($company, $app);

        $this->expectException(ValidationException::class);

        new MailgunHandler($app, $company, $region, [
            'api_key' => 'mg-' . uniqid(),
        ])->setup();
    }
}
