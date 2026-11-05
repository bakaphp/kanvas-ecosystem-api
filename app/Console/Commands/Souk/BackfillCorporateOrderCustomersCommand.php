<?php

declare(strict_types=1);

namespace App\Console\Commands\Souk;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Souk\Orders\Actions\AssignCorporateOrderOwnerAction;
use Kanvas\Souk\Orders\Actions\ResolveCorporateOrderCreatorAction;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Souk\Orders\Models\OrderProvider;
use Kanvas\Users\Models\Users;
use Kanvas\Users\Repositories\UsersRepository;
use Throwable;

class BackfillCorporateOrderCustomersCommand extends Command
{
    use KanvasJobsTrait;

    protected $signature = 'kanvas-souk:backfill-corporate-order-customers
                            {app_id}
                            {--order-type-id=* : Order types to backfill, required}
                            {--user-company-id=* : Corporate companies, matched by the order metadata user_company_id or by order_providers. Without it only orders carrying a user_company_id are taken}
                            {--apply : Write the changes, without it only the plan is printed}
                            {--order-id=* : Only these orders}
                            {--limit=0 : Stop after this many orders needing a change}';

    protected $description = 'Set the owner and customer of corporate orders from the user who owns their user_email';

    public function handle(): int
    {
        $app = Apps::getById((int) $this->argument('app_id'));
        $this->overwriteAppService($app);

        $orderTypeIds = array_values(array_filter(array_map('intval', (array) $this->option('order-type-id'))));

        if ($orderTypeIds === []) {
            $this->error('Pass at least one --order-type-id.');

            return self::FAILURE;
        }

        $apply = (bool) $this->option('apply');
        $limit = (int) $this->option('limit');
        $totals = ['changes' => 0, 'unchanged' => 0, 'no user' => 0, 'failed' => 0];

        $this->info($apply ? 'Applying changes.' : 'Dry run, nothing is written. Use --apply to write.');

        $this->ordersOfTypes($app, $orderTypeIds)->chunkById(
            200,
            function (Collection $orders) use ($app, $apply, $limit, &$totals): bool {
                foreach ($orders as $order) {
                    $this->backfill($order, $app, $apply, $totals);

                    if ($limit > 0 && $totals['changes'] >= $limit) {
                        return false;
                    }
                }

                return true;
            }
        );

        $this->table(['result', 'orders'], array_map(null, array_keys($totals), array_values($totals)));

        return self::SUCCESS;
    }

    protected function ordersOfTypes(Apps $app, array $orderTypeIds): Builder
    {
        $query = Order::fromApp($app)
            ->notDeleted()
            ->whereIn('order_types_id', $orderTypeIds)
            ->whereNotNull('user_email')
            ->where('user_email', '!=', '');

        $companyIds = array_values(array_filter(array_map('intval', (array) $this->option('user-company-id'))));

        $query->where(function (Builder $corporate) use ($companyIds): void {
            if ($companyIds === []) {
                $corporate->whereRaw(ResolveCorporateOrderCreatorAction::COMPANY_METADATA_SQL . ' IS NOT NULL');

                return;
            }

            $corporate
                ->whereIn(DB::raw(ResolveCorporateOrderCreatorAction::COMPANY_METADATA_SQL), $companyIds)
                ->orWhereIn('id', OrderProvider::query()->select('order_id')->whereIn('company_id', $companyIds));
        });

        $orderIds = array_filter((array) $this->option('order-id'));

        return $orderIds === [] ? $query : $query->whereIn('id', $orderIds);
    }

    protected function backfill(Order $order, Apps $app, bool $apply, array &$totals): void
    {
        $user = $this->userOwningEmail((string) $order->user_email, $app);

        if ($user === null) {
            $totals['no user']++;
            $this->line("order {$order->getId()}: no user of this app has the email {$order->user_email}");

            return;
        }

        $action = new AssignCorporateOrderOwnerAction($order, $user, $this->corporateCompanyOf($order));
        $existing = $action->existingCustomer($user);

        $ownerChanges = (int) $order->users_id !== $user->getId();
        $customerChanges = $existing === null || (int) $order->people_id !== $existing->getId();

        if (! $ownerChanges && ! $customerChanges) {
            $totals['unchanged']++;

            return;
        }

        $totals['changes']++;
        $this->line(sprintf(
            'order %d: user %d -> %d, people %d -> %s',
            $order->getId(),
            (int) $order->users_id,
            $user->getId(),
            (int) $order->people_id,
            $existing === null ? 'new person from user' : (string) $existing->getId()
        ));

        if (! $apply) {
            return;
        }

        try {
            $action->execute();
        } catch (Throwable $exception) {
            $totals['failed']++;
            report($exception);
            $this->error("order {$order->getId()}: {$exception->getMessage()}");
        }
    }

    protected function corporateCompanyOf(Order $order): ?Companies
    {
        $metadata = $order->metadata ?? [];
        $fromMetadata = (int) ($metadata['data']['user_company_id'] ?? $metadata['user_company_id'] ?? 0);

        if ($fromMetadata > 0) {
            return Companies::find($fromMetadata);
        }

        $companyIds = array_values(array_filter(array_map('intval', (array) $this->option('user-company-id'))));

        return $companyIds === [] ? null : $order->providerCompanies()->whereIn('companies.id', $companyIds)->first();
    }

    protected function userOwningEmail(string $email, Apps $app): ?Users
    {
        try {
            $user = UsersRepository::getUserOfAppByEmail($email, $app);

            return (bool) $user->is_deleted ? null : $user;
        } catch (ModelNotFoundException) {
            return null;
        }
    }
}
