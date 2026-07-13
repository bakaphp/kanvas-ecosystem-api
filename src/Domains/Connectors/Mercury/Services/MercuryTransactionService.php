<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Mercury\Services;

use Illuminate\Support\Carbon;
use Kanvas\Connectors\Mercury\DataTransferObject\MercuryTransaction;

class MercuryTransactionService extends MercuryApiService
{
    /** Mercury's own ceiling; asking for more is an error, not a bigger page. */
    private const int MAX_PAGE_SIZE = 1000;

    /** A runaway cursor would loop forever against a paginating API. Bound it. */
    private const int MAX_PAGES = 100;

    public function find(string $transactionId): ?MercuryTransaction
    {
        $response = $this->client->get("transactions/{$transactionId}");

        return isset($response['id'])
            ? MercuryTransaction::fromApi($response)
            : null;
    }

    /**
     * Settled transactions for ONE Mercury account, oldest first.
     *
     * Uses the path-scoped `/account/{id}/transactions` endpoint, NOT `/transactions?accountId=…`. The
     * collection endpoint silently IGNORES the accountId filter and returns every transaction across every
     * account — verified against the live API. Trusting it stamped card and savings movements onto the
     * checking account's bank row, which would have posted their cash to the wrong GL account. The path form
     * is the only one that actually scopes.
     *
     * `postedStart` is mandatory in practice: without a date filter the endpoint quietly returns only a few
     * recent weeks rather than everything.
     *
     * @return list<MercuryTransaction>
     */
    public function listForAccount(string $mercuryAccountId, ?Carbon $postedSince = null): array
    {
        $query = [
            'status' => ['sent'],
            'limit' => self::MAX_PAGE_SIZE,
            'order' => 'asc',
        ];

        if ($postedSince !== null) {
            // Zulu, not toIso8601String(). Mercury rejects a '+00:00' offset with `malformedDateParam`;
            // it only accepts the trailing-Z form it emits itself.
            $query['postedStart'] = $postedSince->toIso8601ZuluString();
        }

        $transactions = [];
        $cursor = null;
        $pages = 0;

        do {
            if ($cursor !== null) {
                $query['start_after'] = $cursor;
            }

            $response = $this->client->get("account/{$mercuryAccountId}/transactions", $query);
            $rows = (array) ($response['transactions'] ?? []);

            foreach ($rows as $row) {
                $transaction = MercuryTransaction::fromApi((array) $row);

                // The server-side status filter is not trustworthy either (the same bracket-array
                // serialization the accountId filter ignores), and a settled-only ledger is a hard
                // invariant — so re-check rather than rest it on a query parameter.
                if (! $transaction->isSettled()) {
                    continue;
                }

                // Never take Mercury's word that a response is scoped. One endpoint already lied about it,
                // and a transaction filed under the wrong bank account posts its cash to the wrong GL
                // account — a silent, hard-to-spot corruption. Drop anything that isn't ours.
                if ($transaction->accountId !== '' && $transaction->accountId !== $mercuryAccountId) {
                    continue;
                }

                $transactions[] = $transaction;
            }

            // This endpoint returns `page: null`, so nextPage can't drive pagination. Page off the row count
            // instead: a full page means there is probably more, and anything less means we're done. Without
            // this a busy account would silently truncate at the limit and we'd never know.
            $cursor = count($rows) >= self::MAX_PAGE_SIZE
                ? ($response['page']['nextPage'] ?? end($rows)['id'] ?? null)
                : null;

            $pages++;
        } while ($cursor !== null && $pages < self::MAX_PAGES);

        return $transactions;
    }
}
