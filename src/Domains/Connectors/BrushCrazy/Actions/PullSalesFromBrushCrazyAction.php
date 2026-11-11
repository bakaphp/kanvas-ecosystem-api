<?php

declare(strict_types=1);

namespace Kanvas\Connectors\BrushCrazy\Actions;

use Baka\Contracts\AppInterface;
use Baka\Users\Contracts\UserInterface;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Kanvas\Connectors\BrushCrazy\Client;
use Kanvas\Connectors\BrushCrazy\Concerns\PullsSourceInChunks;
use Kanvas\Connectors\BrushCrazy\Enums\CustomFieldEnum;
use Kanvas\Connectors\BrushCrazy\Support\IdMapRepository;
use Kanvas\Connectors\BrushCrazy\Support\SaleSource;
use Kanvas\Connectors\BrushCrazy\Support\StudioContext;
use Kanvas\Souk\Orders\Models\Order;
use Throwable;

/**
 * Bulk side of the sales mirror: refreshes the studio's catalog (full runs only), then walks its sales and hands
 * each to SyncSaleFromBrushCrazyAction, the same write path the webhook uses.
 *
 * Only parent sales are walked. A partial-refund mirror is loaded alongside its parent and netted
 * into it, never synced on its own. Deleted and open sales are walked too: the sync skips them, or
 * soft-deletes an order that was imported before they were voided.
 */
class PullSalesFromBrushCrazyAction
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
     * @return array{synced: int, skipped: int, failed: int, errors: array<int, string>, warnings: array<int, string>, catalog: array{products: int, lines: int}|array{skipped: string}}
     */
    public function execute(?callable $onProgress = null): array
    {
        $client = new Client($this->app);
        $source = new SaleSource($client);
        $catalog = new SyncCommerceCatalogFromBrushCrazyAction(
            $this->app,
            $this->user,
            $this->context,
            client: $client,
        );
        $sync = new SyncSaleFromBrushCrazyAction(
            $this->app,
            $this->user,
            $this->context,
            catalog: $catalog,
            source: $source,
        );

        // The full catalog pass scans every product the studio ever sold. An incremental run leaves
        // it out: variantFor() pulls a product the first time a sale needs it, and catalog edits
        // arrive through the product webhook.
        $results = [
            'synced' => 0,
            'skipped' => 0,
            'failed' => 0,
            'errors' => [],
            'warnings' => [],
            'catalog' => $this->since === null ? $catalog->execute() : ['skipped' => 'incremental'],
        ];
        $visited = [];
        $pricer = $source->pricer();

        $query = $client->table('sales')
            ->where('sales.studio_id', $this->context->bcStudioId)
            ->whereNull('sales.original_sale');

        if ($this->since !== null) {
            $this->applySince($query, $this->sourceSince());
        }

        $query->chunkById(
            $this->chunkSize,
            function ($rows) use ($source, $sync, $pricer, &$results, &$visited, $onProgress) {
                $sales = $source->bundles($rows);
                $refunds = $source->refundsFor(array_keys($sales));
                $sync->preload($sales);
                $orders = [];

                foreach ($rows as $row) {
                    $visited[(int) $row->id] = true;
                }

                foreach ($sales as $saleId => $sale) {
                    try {
                        $order = $sync->fromRows($sale, $refunds[$saleId] ?? [], $pricer);
                        $results[$order !== null ? 'synced' : 'skipped']++;

                        if ($order !== null) {
                            $orders[] = $order;
                        }
                    } catch (Throwable $e) {
                        $results['failed']++;
                        $results['errors'][] = 'sale:' . $saleId . ' — ' . $e->getMessage();
                    }
                }

                // The sync writes with model events off, so Scout never saw these Order saves.
                new EloquentCollection($orders)->searchable();

                if ($onProgress !== null) {
                    $onProgress($rows->count());
                }
            },
            'sales.id',
            'id'
        );

        if ($this->since === null) {
            $this->retireMovedAway($client, $visited, $results);
        }

        return $results;
    }

    /**
     * A sale moved to another studio is only announced to its new studio by the incremental pull,
     * so a full pass checks the mapped sales its walk no longer reached. Full passes only: the
     * source has no `updated_at` index, so a window over every studio's sales is a table scan.
     *
     * @param  array<int, true>  $visited
     * @param  array<string, mixed>  $results
     */
    protected function retireMovedAway(Client $client, array $visited, array &$results): void
    {
        $unvisited = array_values(array_filter(
            array_map('intval', array_keys(new IdMapRepository()->load(
                $this->context->companyId(),
                Order::class,
                CustomFieldEnum::BRUSHCRAZY_SALE_ID
            ))),
            fn (int $saleId) => ! isset($visited[$saleId])
        ));

        $sweep = new RetireMovedBrushCrazyEntityAction($this->app, $this->context)
            ->sweepSales($client, $unvisited);

        $results['failed'] += $sweep['failed'];
        array_push($results['errors'], ...$sweep['errors']);
        array_push($results['warnings'], ...$sweep['warnings']);

        if ($sweep['moved'] > 0) {
            $results['warnings'][] = "{$sweep['moved']} sales moved to another studio; their orders retired here";
        }
    }

    /**
     * A refund can change a sale without the sale's own row moving — the mirror is a new row — so
     * the window also catches parents whose mirrors changed. Item, payment and tax rows touch their
     * sale at the source (`$touches`), so they need no clause of their own.
     */
    protected function applySince(Builder $query, string $since): void
    {
        $query->where(function (Builder $window) use ($since) {
            $window->where('sales.updated_at', '>=', $since)
                ->orWhereExists(
                    fn (Builder $mirrors) => $mirrors->selectRaw('1')
                        ->from('sales as mirrors')
                        ->whereColumn('mirrors.original_sale', 'sales.id')
                        ->where('mirrors.updated_at', '>=', $since)
                );
        });
    }
}
