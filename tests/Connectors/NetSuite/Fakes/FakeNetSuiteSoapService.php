<?php

declare(strict_types=1);

namespace Tests\Connectors\NetSuite\Fakes;

use Closure;
use NetSuite\Classes\GetRequest;
use NetSuite\Classes\Record;
use NetSuite\Classes\SearchMoreWithIdRequest;
use NetSuite\Classes\SearchRequest;
use NetSuite\NetSuiteService;

/**
 * Serves records from memory in the shape SuiteTalk answers `get` and `search`. The parent constructor
 * is skipped on purpose: it opens a SOAP client against the account in the app config.
 */
class FakeNetSuiteSoapService extends NetSuiteService
{
    /** @var array<string, Record> keyed "{type}:{internalId}" */
    public array $records = [];

    /** @var list<Record> */
    public array $searchResults = [];

    public ?SearchRequest $lastSearch = null;

    /** Answers a search by its request when set; `$searchResults` otherwise. */
    public ?Closure $searchBy = null;

    /**
     * @var list<list<object>> When set, search() answers the first page and searchMoreWithId() the
     *                         rest, the way SuiteTalk splits a large result.
     */
    public array $pages = [];

    /** @var list<int> page indexes asked of searchMoreWithId, in call order */
    public array $morePages = [];

    /** @var list<string> "{type}:{internalId}" in call order */
    public array $gets = [];

    public function __construct()
    {
    }

    public function serve(string $type, Record $record): self
    {
        $this->records[$type . ':' . $record->internalId] = $record;

        return $this;
    }

    public function get(GetRequest $arg)
    {
        $key = $arg->baseRef->type . ':' . $arg->baseRef->internalId;
        $this->gets[] = $key;
        $record = $this->records[$key] ?? null;

        return (object) [
            'readResponse' => (object) [
                'status' => (object) [
                    'isSuccess' => $record !== null,
                    'statusDetail' => [(object) ['message' => 'That record does not exist.']],
                ],
                'record' => $record,
            ],
        ];
    }

    public function search(SearchRequest $arg)
    {
        $this->lastSearch = $arg;

        $records = match (true) {
            $this->searchBy !== null => ($this->searchBy)($arg),
            $this->pages !== [] => $this->pages[0],
            default => $this->searchResults,
        };

        return $this->page($records, 1);
    }

    public function searchMoreWithId(SearchMoreWithIdRequest $arg)
    {
        $this->morePages[] = $arg->pageIndex;

        return $this->page($this->pages[$arg->pageIndex - 1] ?? [], $arg->pageIndex);
    }

    /**
     * A basic search answers in recordList and an advanced one in searchRowList; the fake fills both
     * so one list of objects serves either kind of search.
     */
    private function page(array $records, int $pageIndex): object
    {
        return (object) [
            'searchResult' => (object) [
                'status' => (object) ['isSuccess' => true, 'statusDetail' => []],
                'searchId' => 'fake-search',
                'totalPages' => max(1, count($this->pages)),
                'pageIndex' => $pageIndex,
                'recordList' => (object) ['record' => $records],
                'searchRowList' => (object) ['searchRow' => $records],
            ],
        ];
    }
}
