<?php

declare(strict_types=1);

namespace Tests\Connectors\Microsoft\OneDrive;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Microsoft\Enums\ConfigurationEnum;
use Kanvas\Connectors\Microsoft\OneDrive\Enums\OneDriveDriveEnum;
use Kanvas\Connectors\Microsoft\OneDrive\Enums\OneDriveErrorReasonEnum;
use Kanvas\Connectors\Microsoft\OneDrive\Exceptions\OneDriveException;
use Kanvas\Connectors\Microsoft\OneDrive\OneDriveClient;
use Kanvas\Connectors\Microsoft\Support\MicrosoftScopes;
use Kanvas\Connectors\Microsoft\Support\MicrosoftTokenStore;
use Kanvas\Intelligence\Agents\Neuron\Tools\OneDrive\ListOneDriveFolderTool;
use PHPUnit\Framework\Attributes\Group;
use ReflectionProperty;
use Tests\Connectors\Microsoft\Concerns\KeepsMicrosoftSettings;
use Tests\TestCase;

/**
 * Serial: both connections live in app and company settings, which every process shares.
 */
#[Group('serial')]
final class OneDriveDriveResolutionTest extends TestCase
{
    use DatabaseTransactions;
    use KeepsMicrosoftSettings;

    private Apps $kanvasApp;
    private Companies $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->kanvasApp = app(Apps::class);
        $this->company = static::$cachedUser->getCurrentCompany();
        $this->keepMicrosoftSettings($this->kanvasApp, $this->company);
        $this->appStore()->forget();
        $this->companyStore()->forget();
    }

    protected function tearDown(): void
    {
        $this->restoreMicrosoftSettings();

        parent::tearDown();
    }

    public function test_the_company_drive_is_the_default_when_the_company_granted_files(): void
    {
        $this->connect($this->appStore(), MicrosoftScopes::ONEDRIVE);
        $this->connect($this->companyStore(), MicrosoftScopes::MAIL . ' Files.ReadWrite');
        $this->companyStore()->set(ConfigurationEnum::ONEDRIVE_ROOT_FOLDER, 'ACME/');

        $client = OneDriveClient::resolve($this->kanvasApp, $this->company);

        $this->assertSame(OneDriveDriveEnum::COMPANY, $client->drive());
        $this->assertSame('/ACME', new ReflectionProperty(OneDriveClient::class, 'rootFolder')->getValue($client));
        $this->assertSame(OneDriveDriveEnum::APP, OneDriveClient::resolve($this->kanvasApp, $this->company, OneDriveDriveEnum::APP)->drive());
    }

    public function test_a_company_connected_for_mail_only_falls_back_to_the_app_drive(): void
    {
        $this->connect($this->appStore(), MicrosoftScopes::ONEDRIVE);
        $this->connect($this->companyStore(), MicrosoftScopes::MAIL);

        $this->assertSame(OneDriveDriveEnum::APP, OneDriveClient::resolve($this->kanvasApp, $this->company)->drive());
        $this->assertNotConnected(
            'This company has not connected its own OneDrive.',
            fn () => OneDriveClient::resolve($this->kanvasApp, $this->company, OneDriveDriveEnum::COMPANY)
        );
    }

    public function test_with_neither_connected_the_answer_says_so(): void
    {
        $this->assertNotConnected('No OneDrive is connected', fn () => OneDriveClient::resolve($this->kanvasApp, $this->company));
        $this->assertNotConnected(
            'The app\'s shared OneDrive is not connected.',
            fn () => OneDriveClient::resolve($this->kanvasApp, $this->company, OneDriveDriveEnum::APP)
        );
    }

    public function test_tools_say_which_drive_answered_and_refuse_an_unknown_one(): void
    {
        $this->connect($this->appStore(), MicrosoftScopes::ONEDRIVE);
        Http::fake(['*' => Http::response(['value' => []])]);

        $answered = $this->listTool()->__invoke();
        $this->assertTrue($answered['success']);
        $this->assertSame('app', $answered['drive']);

        $refused = $this->listTool()->__invoke(drive: 'personal');
        $this->assertSame('invalid_input', $refused['reason']);
        Http::assertSentCount(1);
    }

    private function listTool(): ListOneDriveFolderTool
    {
        return new ListOneDriveFolderTool()->withContext($this->kanvasApp, $this->company, static::$cachedUser);
    }

    private function connect(MicrosoftTokenStore $store, string $grantedScopes): void
    {
        $store->set(ConfigurationEnum::ACCESS_TOKEN, 'token');
        $store->set(ConfigurationEnum::REFRESH_TOKEN, 'refresh');
        $store->set(ConfigurationEnum::TOKEN_EXPIRES_AT, now()->addHour()->toIso8601String());
        $store->set(ConfigurationEnum::GRANTED_SCOPES, $grantedScopes);
    }

    private function assertNotConnected(string $message, callable $resolve): void
    {
        try {
            $resolve();
        } catch (OneDriveException $e) {
            $this->assertSame(OneDriveErrorReasonEnum::NOT_CONNECTED, $e->reason);
            $this->assertStringContainsString($message, $e->getMessage());

            return;
        }

        $this->fail('OneDriveException was not thrown');
    }

    private function appStore(): MicrosoftTokenStore
    {
        return MicrosoftTokenStore::forAppOneDrive($this->kanvasApp);
    }

    private function companyStore(): MicrosoftTokenStore
    {
        return MicrosoftTokenStore::forCompanyMail($this->kanvasApp, $this->company);
    }
}
