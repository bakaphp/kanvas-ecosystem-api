<?php

declare(strict_types=1);

namespace App\Console\Commands\Inventory;

use Baka\Search\Activities\PushEntityToSecondaryIndexActivity;
use Baka\Traits\KanvasJobsTrait;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Inventory\Channels\Models\Channels;
use Kanvas\Inventory\Channels\Repositories\ChannelRepository;
use Kanvas\Inventory\Products\Models\Products;
use Kanvas\Inventory\Variants\DataTransferObject\VariantChannel;
use Kanvas\Inventory\Variants\Models\Variants;
use Kanvas\Inventory\Variants\Services\VariantService;
use Kanvas\Workflow\Models\StoredWorkflow;
use RuntimeException;
use Throwable;

class PushCompanyVehiclesToChannelCommand extends Command
{
    use KanvasJobsTrait;

    /**
     * Fixed list of dealer companies this one-off command targets, matched by name.
     *
     * @var array<string>
     */
    private const COMPANY_NAMES = [
        'Alberic Auto Bayamon',
        'Alberic Colon Chrysler',
        'Alberic Colon Mitsubishi',
        'All Brand Auto',
        'Ambar Infiniti',
        'Atlantic Toyota Hatillo',
        'Audi San Juan',
        'Auto Amigo',
        'Auto Ofertas Caguas',
        'Auto Stop Aguada',
        'Auto Stop Kia',
        'Autocentro Chrysler',
        'Autocentro Más Guaynabo',
        'Autocentro Más San Juan',
        'Autogermana BMW',
        'Autogermana Mini',
        'Autogermana Pre-Owned',
        'AutoGrupo Kia',
        'AutoGrupo Nissan 65',
        'AutoGrupo Nissan Kennedy',
        'Autos Vega Ford',
        'Autos Vega Hormigueros',
        'Aviles Auto',
        'Braulio Agosto Mitsubishi',
        'Braulio Agosto Toyota',
        'Cabrera Ford',
        'Cabrera GM',
        'Caguas Expressway Motors',
        'CarFest',
        'Central Ford',
        'Ebenezer Auto',
        'Eurojapón Distributors',
        'Flagship Acura de Ponce',
        'Flagship Acura de San Juan',
        'Flagship Ford Carolina',
        'Flagship Ford del Sur',
        'Flagship Ford Rio Grande',
        'Flagship Honda de Bayamón',
        'Flagship Honda de Caguas',
        'Flagship Honda de Cayey',
        'Flagship Honda de Ponce',
        'Flagship Honda de Río Grande',
        'Flagship Honda de San Juan',
        'Flagship Hyundai Carolina',
        'Flagship Hyundai Escorial',
        'Flagship Jeep RAM',
        'Flagship Kia Carolina',
        'Flagship Mazda Bayamón',
        'Flagship Mazda Carolina',
        'Flagship Mazda Cayey',
        'Flagship Mazda Kennedy',
        'Flagship Mazda Ponce',
        'Flagship Mazda Rio Grande',
        'Flagship Mitsubishi Carolina',
        'Flagship Volkswagen Bayamón',
        'Flagship Volkswagen Ponce',
        'Garage Isla Verde',
        'Hyundai De Bayamón',
        'Hyundai de Caguas',
        'Hyundai de Hormigueros',
        'Hyundai de San Sebastian',
        'Hyundai De Vega Alta',
        'Hyundai Rexville',
        'JR Automotive',
        'Magic Auto Corp',
        'Medina Auto Kia',
        'Medina Auto Nissan',
        'MVP Toyota Caguas',
        'New Era Wholesaler',
        'Nissan de Aguadilla',
        'Pita Auto Sales',
        'San Juan Lincoln',
        'Señorial Auto Kia',
        'Señorial Auto Mitsubishi',
        'Taino Motors',
        'Tocars Kia',
        'Tocars Toa Baja',
        'Tocars Vega Baja',
        "Toñito D`Outlet",
        'Triangle Chrysler Ponce',
        'Triangle Dealer Del Oeste',
        'Triangle Fajardo',
        'Villa Victoria Auto',
        'Volkswagen Kennedy',
        'Volvo Car Puerto Rico',
        'Yokomuro Kia Bayamón',
        'Yokomuro Nissan',
    ];

    protected $signature = 'kanvas-inventory:push-company-vehicles-to-channel
        {app_id}
        {--channel_id= : Add each qualifying variant to this Channel id — fires the existing channel-saved workflow}
        {--index_name= : Also push each qualifying product directly into this secondary search index}
        {--search_engine=algolia : Search engine for --index_name, forwarded to PushEntityToSecondaryIndexActivity}
        {--confirm : Actually write changes. Without it, only a preview is printed}
        {--limit= : Cap the number of qualifying vehicles processed, for a sample run}';

    protected $description = 'Finds vehicles (>1 photo, has a warehouse price) for the fixed dealer company list, and adds them to a channel and/or a secondary search index.';

    /**
     * @var array<string>
     */
    private array $unmatchedNames = [];

    /**
     * @var array<string>
     */
    private array $ambiguousNames = [];

    public function handle(): int
    {
        $app = Apps::getById((int) $this->argument('app_id'));
        $this->overwriteAppService($app);

        $channelId = $this->option('channel_id');
        $indexName = $this->option('index_name');
        $searchEngine = $this->option('search_engine') ?? 'algolia';
        $confirm = (bool) $this->option('confirm');
        $limit = $this->option('limit') !== null ? (int) $this->option('limit') : null;

        if (! $channelId && ! $indexName) {
            $this->error('Pass at least one of --channel_id or --index_name.');

            return self::FAILURE;
        }

        $matched = $this->matchCompaniesByName(self::COMPANY_NAMES, $app);
        $this->printMatchSummary($matched);

        if (! $matched) {
            $this->warn('No companies matched, nothing to do.');

            return self::SUCCESS;
        }

        // Channel is resolved once, assuming a shared/global (companies_id=0) "popular" channel —
        // ChannelRepository::getByIdOrGlobal() only needs *a* company to build the query, and a
        // global channel resolves the same regardless of which one is passed.
        $channel = $channelId ? ChannelRepository::getByIdOrGlobal((int) $channelId, reset($matched), $app) : null;

        [$processed, $succeeded, $failed] = $this->processCompanies(
            $matched,
            $app,
            $channel,
            $indexName,
            $searchEngine,
            $confirm,
            $limit
        );

        if (! $confirm) {
            $this->warn("\nPreview only, nothing was written. Re-run with --confirm to apply.");

            return self::SUCCESS;
        }

        $this->info("\nDone. Processed: {$processed}, Succeeded: {$succeeded}, Failed: {$failed}");

        return self::SUCCESS;
    }

    /**
     * @return array{0: int, 1: int, 2: int} [$processed, $succeeded, $failed]
     */
    private function processCompanies(
        array $matched,
        Apps $app,
        ?Channels $channel,
        ?string $indexName,
        string $searchEngine,
        bool $confirm,
        ?int $limit
    ): array {
        $processed = 0;
        $succeeded = 0;
        $failed = 0;

        foreach ($matched as $name => $company) {
            $vehicles = $this->qualifyingVehiclesFor($company, $app);

            $this->info("\n{$name} (company {$company->id}): {$vehicles->count()} qualifying vehicle(s)");

            foreach ($vehicles as $product) {
                if ($limit !== null && $processed >= $limit) {
                    break 2;
                }

                $processed++;
                $this->line("  - product {$product->id} ({$product->name})");

                if (! $confirm) {
                    continue;
                }

                try {
                    $this->pushProduct(
                        $product,
                        $app,
                        $channel,
                        $indexName,
                        $searchEngine
                    );
                    $succeeded++;
                } catch (Throwable $e) {
                    $failed++;
                    report($e);
                    $this->error("    failed: {$e->getMessage()}");
                }
            }
        }

        return [$processed, $succeeded, $failed];
    }

    private function pushProduct(
        Products $product,
        Apps $app,
        ?Channels $channel,
        ?string $indexName,
        string $searchEngine
    ): void {
        /** @var Variants|null $variant */
        $variant = $product->variants->first();

        if (! $variant) {
            throw new RuntimeException("Product {$product->id} has no variant");
        }

        if ($channel) {
            $this->pushVariantToChannel($variant, $channel);
        }

        if ($indexName) {
            $this->pushProductToIndex(
                $product,
                $app,
                $indexName,
                $searchEngine
            );
        }
    }

    private function pushVariantToChannel(Variants $variant, Channels $channel): void
    {
        $variantWarehouse = $variant->variantWarehouses()
            ->where('price', '>', 0)
            ->where('is_deleted', 0)
            ->first();

        if (! $variantWarehouse) {
            throw new RuntimeException("No priced warehouse row found for variant {$variant->id}");
        }

        VariantService::addVariantChannel(
            $variant,
            $variantWarehouse->warehouse,
            $channel,
            new VariantChannel(
                price: (float) $variantWarehouse->price,
                is_published: true,
            )
        );
    }

    private function pushProductToIndex(
        Products $product,
        Apps $app,
        string $indexName,
        string $searchEngine
    ): void {
        $activity = new PushEntityToSecondaryIndexActivity(0, now()->toDateTimeString(), new StoredWorkflow(), []);

        $result = $activity->execute($product, $app, [
            'index_name' => $indexName,
            'search_engine' => $searchEngine,
        ]);

        if (! $result['result']) {
            throw new RuntimeException($result['message'] ?? 'Unknown PushEntityToSecondaryIndexActivity failure');
        }
    }

    /**
     * More than one PHOTO (Products::photos() — the files() relation filtered to actual image
     * types, not brochures/PDFs/etc.) AND at least one warehouse row with a real price — "has a
     * price" is read at the warehouse level, not the channel-scoped default price, so this still
     * works for a company with no default channel set up.
     */
    private function qualifyingVehiclesFor(Companies $company, Apps $app): Collection
    {
        return Products::query()
            ->fromApp($app)
            ->fromCompany($company)
            ->notDeleted()
            ->with('variants')
            ->whereHas('variants.variantWarehouses', function ($query) {
                $query->where('price', '>', 0)->where('is_deleted', 0);
            })
            ->get()
            ->filter(fn (Products $product) => $product->getPhotosCount() > 1)
            ->values();
    }

    /**
     * @param array<string> $names
     * @return array<string, Companies>
     */
    private function matchCompaniesByName(array $names, Apps $app): array
    {
        $matched = [];

        foreach ($names as $name) {
            [$company, $ambiguous] = $this->resolveCompanyByName($name, $app);

            if ($company) {
                $matched[$name] = $company;
            } elseif ($ambiguous) {
                $this->ambiguousNames[] = $name;
            } else {
                $this->unmatchedNames[] = $name;
            }
        }

        return $matched;
    }

    /**
     * Exact match first; a single LIKE candidate as fallback for names that don't match
     * character-for-character. Zero or more-than-one LIKE candidates are never guessed — they're
     * reported for manual review instead.
     *
     * @return array{0: Companies|null, 1: bool} [$company, $isAmbiguous]
     */
    private function resolveCompanyByName(string $name, Apps $app): array
    {
        $exact = Companies::query()->fromApp($app)->notDeleted()->where('name', $name)->first();

        if ($exact) {
            return [$exact, false];
        }

        $candidates = Companies::query()->fromApp($app)->notDeleted()->where('name', 'like', '%' . $name . '%')->get();

        if ($candidates->count() === 1) {
            return [$candidates->first(), false];
        }

        return [null, $candidates->count() > 1];
    }

    /**
     * @param array<string, Companies> $matched
     */
    private function printMatchSummary(array $matched): void
    {
        $this->info("\nMatched " . count($matched) . ' compan(ies):');
        foreach ($matched as $name => $company) {
            $this->line("  {$name} -> company {$company->id}");
        }

        if ($this->unmatchedNames) {
            $this->warn("\nNo match found (0 candidates), skipped:");
            foreach ($this->unmatchedNames as $name) {
                $this->line("  {$name}");
            }
        }

        if ($this->ambiguousNames) {
            $this->warn("\nMore than one candidate matched, skipped — needs manual review:");
            foreach ($this->ambiguousNames as $name) {
                $this->line("  {$name}");
            }
        }
    }
}
