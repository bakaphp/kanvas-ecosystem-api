<?php

declare(strict_types=1);

namespace App\Console\Commands\Inventory;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Inventory\Products\Models\Products;
use Kanvas\Inventory\Recommendations\Actions\RecommendProductsAction;
use Kanvas\Inventory\Recommendations\DataTransferObject\ProductIntent;
use Kanvas\Inventory\Recommendations\Enums\SearchFieldEnum;
use Kanvas\Inventory\Recommendations\Services\IntentLexiconService;
use Kanvas\Inventory\Recommendations\Services\ProductDiscoveryResolver;
use Throwable;

/**
 * Run one search the way the storefront would, and show what came back.
 *
 * `--explain` prints the blurb each result matched on, which is the only way to
 * answer the question that actually matters when a result looks wrong: was it
 * the search, or was it a bad blurb?
 */
class DiscoverProductsCommand extends Command
{
    use KanvasJobsTrait;

    /**
     * @var string
     */
    protected $signature = 'kanvas-inventory:discover-products
                            {app_id : App to search}
                            {company_id : Company whose catalog to search}
                            {query* : The shopper request, in their own words}
                            {--limit=8 : How many results}
                            {--explain : Show the blurb each result matched on}
                            {--fresh : Bypass the query cache}';

    /**
     * @var string
     */
    protected $description = 'Run a natural-language product search from the console and show the results';

    public function handle(): int
    {
        /** @var Apps $app */
        $app = Apps::getById((int) $this->argument('app_id'));
        $this->overwriteAppService($app);

        /** @var Companies $company */
        $company = Companies::getById((int) $this->argument('company_id'));

        // Words arrive as separate argv entries; the sentence is the input.
        $query = implode(' ', (array) $this->argument('query'));

        if ($this->option('fresh')) {
            Cache::flush();
        }

        $intent = ProductIntent::fromSentence($query, new IntentLexiconService($app));
        $engine = new ProductDiscoveryResolver($app, $company)->isOnTypesense() ? 'typesense' : 'sql';

        $this->line("<comment>\"{$query}\"</comment>");
        $this->line(sprintf(
            '  engine=%s  budget=%s  audience=%s  in_stock_only=%s',
            $engine,
            $this->describeBudget($intent),
            $intent->audience?->value ?? 'any',
            $intent->inStockOnly ? 'yes' : 'no',
        ));

        $start = microtime(true);

        try {
            $results = new RecommendProductsAction($app, $company)
                ->execute($query, (int) $this->option('limit'));
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $elapsed = (microtime(true) - $start) * 1000;

        if ($results === []) {
            $this->newLine();
            $this->warn(sprintf('No results (%.0fms).', $elapsed));
            $this->line('  Either the catalog has nothing like it, or the blurbs do not describe it that way.');

            return self::SUCCESS;
        }

        $this->newLine();

        foreach ($results as $i => $result) {
            $this->renderResult($i + 1, $result);
        }

        $this->newLine();
        $this->line(sprintf('<info>%d result(s) in %.0fms</info>', count($results), $elapsed));

        return self::SUCCESS;
    }

    /**
     * @param array{product: array, variants: array} $result
     */
    private function renderResult(int $rank, array $result): void
    {
        $variant = $result['variants'][0] ?? [];
        $channel = $variant['channel'] ?? [];
        $price = $channel['price'] ?? null;

        $this->line(sprintf(
            '  <info>%2d.</info> %-52s %10s  %s',
            $rank,
            mb_strimwidth((string) $result['product']['name'], 0, 50, '…'),
            $price > 0 ? number_format((float) $price, 2) : 'no price',
            ($channel['is_available'] ?? false) ? 'available' : 'unavailable',
        ));

        if (! $this->option('explain')) {
            return;
        }

        $product = Products::find($result['product']['id']);
        $blurb = (string) ($product?->get(SearchFieldEnum::BLURB->value) ?? '');

        $this->line('      ' . ($blurb !== ''
            ? mb_strimwidth($blurb, 0, 150, '…')
            : '<comment>(no blurb — matched on name/description only)</comment>'));
    }

    private function describeBudget(ProductIntent $intent): string
    {
        return match (true) {
            $intent->minPrice !== null && $intent->maxPrice !== null => $intent->minPrice . '-' . $intent->maxPrice,
            $intent->minPrice !== null => 'over ' . $intent->minPrice,
            $intent->maxPrice !== null => 'under ' . $intent->maxPrice,
            default => 'none',
        };
    }
}
