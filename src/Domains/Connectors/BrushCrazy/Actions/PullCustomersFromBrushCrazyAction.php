<?php

declare(strict_types=1);

namespace Kanvas\Connectors\BrushCrazy\Actions;

use Baka\Contracts\AppInterface;
use Baka\Users\Contracts\UserInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Kanvas\Connectors\BrushCrazy\Client;
use Kanvas\Connectors\BrushCrazy\Concerns\PullsSourceInChunks;
use Kanvas\Connectors\BrushCrazy\Support\StudioContext;
use stdClass;

/**
 * Bulk side of the customer mirror: walks the users that belong to one studio and hands each to
 * SyncCustomerFromBrushCrazyAction, the same write path the webhook uses.
 *
 * Soft-deleted users are read on purpose and imported flagged — their past registrations still
 * point at them.
 */
class PullCustomersFromBrushCrazyAction
{
    use PullsSourceInChunks;

    public function __construct(
        protected AppInterface $app,
        protected UserInterface $user,
        protected StudioContext $context,
        protected ?Carbon $since = null,
        protected int $chunkSize = 500,
    ) {
    }

    /**
     * A user belongs to a studio when they bought there (any sale, refunded ones included) or
     * BrushCrazy has pinned the studio on their profile. A user with none of those — an account
     * that never bought and never picked a studio — belongs to no company and is not imported.
     *
     * Studio 0 (Corporate) falls out naturally: online e-gift-card sales are `sales.studio_id = 0`.
     *
     * Shared with the webhook so "is this user a customer of this studio" has one definition.
     */
    public static function customersOfStudio(Client $client, int $bcStudioId): Builder
    {
        return $client->table('users')
            ->select('users.*')
            ->where(function (Builder $query) use ($bcStudioId) {
                $query->where('users.last_studio', $bcStudioId)
                    ->orWhere('users.favorite_studio', $bcStudioId)
                    ->orWhere('users.registration_location', $bcStudioId)
                    ->orWhereExists(
                        fn (Builder $sales) => $sales->selectRaw('1')
                            ->from('sales')
                            ->whereColumn('sales.customer_id', 'users.id')
                            ->where('sales.studio_id', $bcStudioId)
                    );
            });
    }

    /**
     * @return array{synced: int, failed: int, errors: array<int, string>}
     */
    public function execute(?callable $onProgress = null): array
    {
        $client = new Client($this->app);
        $sync = new SyncCustomerFromBrushCrazyAction($this->app, $this->user, $this->context);
        $results = ['synced' => 0, 'failed' => 0, 'errors' => []];

        $query = self::customersOfStudio($client, $this->context->bcStudioId);
        $since = $this->sourceSince();

        if ($since !== null) {
            $this->applySince($query, $since);
        }

        $this->pullChunks(
            $query,
            'users.id',
            'user',
            fn (array $rows) => $sync->preloadRows($rows),
            fn (stdClass $row) => $sync->fromRow($row),
            $results,
            $onProgress
        );

        return $results;
    }

    /**
     * A user can join a studio, or have their studio credit change, without their own row changing
     * — buying in a new studio only writes a sale, a credit only writes `customer_credits` — so the
     * window also catches recent sales and credits in this studio.
     */
    protected function applySince(Builder $query, string $since): void
    {
        $bcStudioId = $this->context->bcStudioId;

        $query->where(function (Builder $window) use ($since, $bcStudioId) {
            $window->where('users.updated_at', '>=', $since)
                ->orWhereExists(
                    fn (Builder $sales) => $sales->selectRaw('1')
                        ->from('sales')
                        ->whereColumn('sales.customer_id', 'users.id')
                        ->where('sales.studio_id', $bcStudioId)
                        ->where('sales.updated_at', '>=', $since)
                )
                ->orWhereExists(
                    fn (Builder $credits) => $credits->selectRaw('1')
                        ->from('customer_credits')
                        ->whereColumn('customer_credits.user_id', 'users.id')
                        ->where('customer_credits.studio_id', $bcStudioId)
                        ->where('customer_credits.updated_at', '>=', $since)
                );
        });
    }
}
