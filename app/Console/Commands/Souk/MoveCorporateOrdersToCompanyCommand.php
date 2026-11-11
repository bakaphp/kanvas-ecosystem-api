<?php

declare(strict_types=1);

namespace App\Console\Commands\Souk;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Souk\Orders\Actions\AssignCorporateOrderOwnerAction;
use Kanvas\Souk\Orders\Actions\MoveOrderToCompanyAction;
use Kanvas\Souk\Orders\Actions\ResolveCorporateOrderCreatorAction;
use Kanvas\Souk\Orders\Actions\SyncOrderProvidersAction;
use Kanvas\Souk\Orders\Models\Order;
use Throwable;

class MoveCorporateOrdersToCompanyCommand extends Command
{
    use KanvasJobsTrait;

    public const ORIGIN_METADATA_KEY = 'moved_from_company_id';

    protected $signature = 'kanvas-souk:move-corporate-orders-to-company
                            {app_id}
                            {--to-company-id= : Company the orders move to, defaults to the app B2B_MAIN_COMPANY_ID}
                            {--user-company-id=* : Only orders whose metadata user_company_id is one of these}
                            {--order-type-id=* : Only these order types}
                            {--order-id=* : Only these orders}
                            {--apply : Write the changes, without it only the plan is printed}
                            {--limit=0 : Stop after this many orders}';

    protected $description = 'Move corporate orders (metadata user_company_id) that were created on another company to the main company';

    public function handle(): int
    {
        $app = Apps::getById((int) $this->argument('app_id'));
        $this->overwriteAppService($app);

        $targetId = (int) ($this->option('to-company-id') ?: $app->get('B2B_MAIN_COMPANY_ID'));

        if ($targetId <= 0) {
            $this->error('Pass --to-company-id, the app has no B2B_MAIN_COMPANY_ID.');

            return self::FAILURE;
        }

        $target = Companies::getById($targetId);
        $apply = (bool) $this->option('apply');
        $limit = (int) $this->option('limit');
        $totals = ['to move' => 0, 'moved' => 0, 'failed' => 0];

        $this->info($apply ? 'Applying changes.' : 'Dry run, nothing is written. Use --apply to write.');
        $this->info("Target company: {$target->getId()} {$target->name}");

        $this->corporateOrdersOutside($app, $target)->chunkById(
            200,
            function (Collection $orders) use ($target, $apply, $limit, &$totals): bool {
                foreach ($orders as $order) {
                    $this->move(
                        $order,
                        $target,
                        $apply,
                        $totals
                    );

                    if ($limit > 0 && $totals['to move'] >= $limit) {
                        return false;
                    }
                }

                return true;
            }
        );

        $this->table(['result', 'orders'], array_map(null, array_keys($totals), array_values($totals)));

        return self::SUCCESS;
    }

    protected function corporateOrdersOutside(Apps $app, Companies $target): Builder
    {
        $companyIds = array_values(array_filter(array_map('intval', (array) $this->option('user-company-id'))));
        $orderTypeIds = array_values(array_filter(array_map('intval', (array) $this->option('order-type-id'))));
        $orderIds = array_values(array_filter(array_map('intval', (array) $this->option('order-id'))));

        return Order::fromApp($app)
            ->notDeleted()
            ->where('companies_id', '!=', $target->getId())
            ->when(
                $companyIds === [],
                fn (Builder $query) => $query->whereRaw(ResolveCorporateOrderCreatorAction::COMPANY_METADATA_SQL . ' IS NOT NULL'),
                fn (Builder $query) => $query->whereIn(DB::raw(ResolveCorporateOrderCreatorAction::COMPANY_METADATA_SQL), $companyIds)
            )
            ->when($orderTypeIds !== [], fn (Builder $query) => $query->whereIn('order_types_id', $orderTypeIds))
            ->when($orderIds !== [], fn (Builder $query) => $query->whereIn('id', $orderIds));
    }

    protected function move(
        Order $order,
        Companies $target,
        bool $apply,
        array &$totals
    ): void {
        $action = new MoveOrderToCompanyAction($order, $target);

        $totals['to move']++;
        $this->line(sprintf(
            'order %d #%s: company %d -> %d%s',
            $order->getId(),
            (string) $order->order_number,
            (int) $order->companies_id,
            $target->getId(),
            $action->numberIsTaken() ? ', number taken in the target company, a new one is generated' : ''
        ));

        if (! $apply) {
            return;
        }

        try {
            $order->getConnection()->transaction(function () use ($order, $action): void {
                $order->keepFirstMetadataData(self::ORIGIN_METADATA_KEY, (int) $order->companies_id);
                $action->execute();
                new SyncOrderProvidersAction($order)->execute();
            });

            $order->addTag(AssignCorporateOrderOwnerAction::CORPORATE_TAG);
            $order->searchable();
            $totals['moved']++;
        } catch (Throwable $exception) {
            $totals['failed']++;
            report($exception);
            $this->error("order {$order->getId()}: {$exception->getMessage()}");
        }
    }
}
