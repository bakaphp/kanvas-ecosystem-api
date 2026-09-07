<?php

declare(strict_types=1);

namespace Tests\Connectors\Jira;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Jira\Client;
use Kanvas\Connectors\Jira\Enums\ConfigurationEnum;
use Kanvas\Connectors\Jira\Exceptions\JiraException;
use Kanvas\Exceptions\ValidationException;
use Tests\TestCase;

final class ClientTest extends TestCase
{
    private const string INSTANCE_URL = 'https://kanvas.atlassian.net';

    private Apps $currentApp;
    private Companies $currentCompany;

    protected function setUp(): void
    {
        parent::setUp();

        $this->currentApp = app(Apps::class);
        $this->currentCompany = static::$cachedUser->getCurrentCompany();

        $this->currentCompany->set(ConfigurationEnum::INSTANCE_URL->value, self::INSTANCE_URL);
        $this->currentCompany->set(ConfigurationEnum::EMAIL->value, 'agent@kanvas.test');
        $this->currentCompany->set(ConfigurationEnum::API_TOKEN->value, 'test-api-token');
    }

    public function testConstructorThrowsWhenConfigurationIsMissing(): void
    {
        $company = static::$cachedUser->getCurrentCompany();
        $company->set(ConfigurationEnum::INSTANCE_URL->value, '');
        $company->set(ConfigurationEnum::EMAIL->value, '');
        $company->set(ConfigurationEnum::API_TOKEN->value, '');

        $this->expectException(ValidationException::class);

        new Client($this->currentApp, $company);
    }

    public function testGetSendsBasicAuthAndReturnsDecodedJson(): void
    {
        Http::fake([
            self::INSTANCE_URL . '/rest/api/3/issue/OPS-1' => Http::response(['id' => '10001', 'key' => 'OPS-1']),
        ]);

        $client = new Client($this->currentApp, $this->currentCompany);
        $issue = $client->get('issue/OPS-1');

        $this->assertSame('OPS-1', $issue['key']);

        Http::assertSent(function (Request $request): bool {
            $header = $request->header('Authorization')[0] ?? '';

            return str_starts_with($header, 'Basic ')
                && base64_decode(substr($header, 6)) === 'agent@kanvas.test:test-api-token';
        });
    }

    public function testFailedResponseIsWrappedInJiraException(): void
    {
        Http::fake([
            self::INSTANCE_URL . '/rest/api/3/issue' => Http::response([
                'errorMessages' => ['summary is required'],
            ], 400),
        ]);

        $client = new Client($this->currentApp, $this->currentCompany);

        $this->expectException(JiraException::class);
        $this->expectExceptionMessage('summary is required');

        $client->post('issue', ['fields' => []]);
    }

    public function testValidateCredentialsReturnsTrueOnSuccess(): void
    {
        Http::fake([
            self::INSTANCE_URL . '/rest/api/3/myself' => Http::response(['accountId' => 'abc123']),
        ]);

        $this->assertTrue(Client::validateCredentials(self::INSTANCE_URL, 'agent@kanvas.test', 'test-api-token'));
    }

    public function testValidateCredentialsRejectsUnauthorized(): void
    {
        Http::fake([
            self::INSTANCE_URL . '/rest/api/3/myself' => Http::response('', 401),
        ]);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Jira rejected');

        Client::validateCredentials(self::INSTANCE_URL, 'agent@kanvas.test', 'bad-token');
    }

    public function testValidateCredentialsRejectsEmptyInput(): void
    {
        $this->expectException(ValidationException::class);

        Client::validateCredentials('', '', '');
    }
}
