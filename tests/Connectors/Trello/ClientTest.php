<?php

declare(strict_types=1);

namespace Tests\Connectors\Trello;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Trello\Client;
use Kanvas\Connectors\Trello\Enums\ConfigurationEnum;
use Kanvas\Connectors\Trello\Exceptions\TrelloException;
use Kanvas\Exceptions\ValidationException;
use Tests\TestCase;

final class ClientTest extends TestCase
{
    private Apps $currentApp;
    private Companies $currentCompany;

    protected function setUp(): void
    {
        parent::setUp();

        $this->currentApp = app(Apps::class);
        $this->currentCompany = static::$cachedUser->getCurrentCompany();

        $this->currentCompany->set(ConfigurationEnum::API_KEY->value, 'test-key');
        $this->currentCompany->set(ConfigurationEnum::API_TOKEN->value, 'test-token');
    }

    public function testConstructorThrowsWhenCredentialsAreMissing(): void
    {
        $company = static::$cachedUser->getCurrentCompany();
        $company->set(ConfigurationEnum::API_KEY->value, '');
        $company->set(ConfigurationEnum::API_TOKEN->value, '');

        $this->expectException(ValidationException::class);

        new Client($this->currentApp, $company);
    }

    public function testGetAppendsKeyAndTokenAndReturnsDecodedJson(): void
    {
        Http::fake([
            'api.trello.com/1/boards/board1*' => Http::response(['id' => 'board1', 'name' => 'Roadmap']),
        ]);

        $client = new Client($this->currentApp, $this->currentCompany);
        $board = $client->get('boards/board1');

        $this->assertSame('Roadmap', $board['name']);

        Http::assertSent(
            fn (Request $request): bool => str_contains($request->url(), 'boards/board1')
                && $request['key'] === 'test-key'
                && $request['token'] === 'test-token'
        );
    }

    public function testCreateCardPostsFormWithAuth(): void
    {
        Http::fake([
            'api.trello.com/1/cards*' => Http::response(['id' => 'card1', 'name' => 'New Card']),
        ]);

        $client = new Client($this->currentApp, $this->currentCompany);
        $card = $client->post('cards', ['idList' => 'list1', 'name' => 'New Card']);

        $this->assertSame('card1', $card['id']);

        Http::assertSent(
            fn (Request $request): bool => $request->method() === 'POST'
                && $request['idList'] === 'list1'
                && $request['name'] === 'New Card'
                && $request['key'] === 'test-key'
                && $request['token'] === 'test-token'
        );
    }

    public function testClientErrorIsWrappedInTrelloException(): void
    {
        Http::fake([
            'api.trello.com/1/members/me*' => Http::response(['message' => 'invalid key'], 401),
        ]);

        $client = new Client($this->currentApp, $this->currentCompany);

        $this->expectException(TrelloException::class);
        $this->expectExceptionMessage('invalid key');

        $client->get('members/me');
    }

    public function testValidateCredentialsReturnsTrueOnSuccess(): void
    {
        Http::fake([
            'api.trello.com/1/members/me*' => Http::response(['id' => 'me']),
        ]);

        $this->assertTrue(Client::validateCredentials('key', 'token'));
    }

    public function testValidateCredentialsRejectsBadToken(): void
    {
        Http::fake([
            'api.trello.com/1/members/me*' => Http::response(['message' => 'invalid token'], 401),
        ]);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Trello rejected');

        Client::validateCredentials('key', 'token');
    }

    public function testValidateCredentialsRejectsEmptyInput(): void
    {
        $this->expectException(ValidationException::class);

        Client::validateCredentials('', '');
    }
}
