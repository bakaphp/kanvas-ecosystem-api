<?php

declare(strict_types=1);

namespace Tests\Connectors\Trello;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Trello\Enums\ConfigurationEnum;
use Kanvas\Connectors\Trello\Services\TrelloBoardService;
use Tests\TestCase;

final class TrelloBoardServiceTest extends TestCase
{
    private TrelloBoardService $service;

    protected function setUp(): void
    {
        parent::setUp();

        /** @var Apps $app */
        $app = app(Apps::class);
        /** @var Companies $company */
        $company = static::$cachedUser->getCurrentCompany();

        $company->set(ConfigurationEnum::API_KEY->value, 'test-key');
        $company->set(ConfigurationEnum::API_TOKEN->value, 'test-token');

        $this->service = TrelloBoardService::forApp($app, $company);
    }

    public function testBoardsRequestsMemberBoards(): void
    {
        Http::fake([
            'api.trello.com/1/members/me/boards*' => Http::response([
                ['id' => 'board1', 'name' => 'Roadmap'],
            ]),
        ]);

        $boards = $this->service->boards();

        $this->assertSame('Roadmap', $boards[0]['name']);
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'members/me/boards'));
    }

    public function testListsFiltersToOpenByDefault(): void
    {
        Http::fake([
            'api.trello.com/1/boards/board1/lists*' => Http::response([
                ['id' => 'list1', 'name' => 'To Do'],
            ]),
        ]);

        $lists = $this->service->lists('board1');

        $this->assertSame('To Do', $lists[0]['name']);
        Http::assertSent(fn (Request $request): bool => $request['filter'] === 'open');
    }

    public function testCreateCardSendsListNameAndDescription(): void
    {
        Http::fake([
            'api.trello.com/1/cards*' => Http::response(['id' => 'card1', 'name' => 'Investigate outage']),
        ]);

        $card = $this->service->createCard('list1', 'Investigate outage', 'Customer reported downtime');

        $this->assertSame('card1', $card['id']);
        Http::assertSent(
            fn (Request $request): bool => $request['idList'] === 'list1'
                && $request['name'] === 'Investigate outage'
                && $request['desc'] === 'Customer reported downtime'
        );
    }

    public function testUpdateCardMovesListWhenRequested(): void
    {
        Http::fake([
            'api.trello.com/1/cards/card1*' => Http::response(['id' => 'card1', 'idList' => 'list2']),
        ]);

        $card = $this->service->moveCardToList('card1', 'list2');

        $this->assertSame('list2', $card['idList']);
        Http::assertSent(
            fn (Request $request): bool => $request->method() === 'PUT' && $request['idList'] === 'list2'
        );
    }

    public function testArchiveCardSendsClosedTrue(): void
    {
        Http::fake([
            'api.trello.com/1/cards/card1*' => Http::response(['id' => 'card1', 'closed' => true]),
        ]);

        $this->service->archiveCard('card1');

        Http::assertSent(fn (Request $request): bool => $request['closed'] === 'true');
    }

    public function testAddCommentPostsToActionsComments(): void
    {
        Http::fake([
            'api.trello.com/1/cards/card1/actions/comments*' => Http::response(['id' => 'comment1']),
        ]);

        $this->service->addComment('card1', 'Looking into this now.');

        Http::assertSent(
            fn (Request $request): bool => str_contains($request->url(), 'actions/comments')
                && $request['text'] === 'Looking into this now.'
        );
    }
}
