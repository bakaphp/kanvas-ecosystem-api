<?php

declare(strict_types=1);

namespace App\Console\Commands\Inventory;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Console\Command;
use InvalidArgumentException;
use Kanvas\Apps\Models\Apps;
use Kanvas\Inventory\Recommendations\Actions\EnableProductDiscoveryAction;

class EnableProductDiscoveryCommand extends Command
{
    use KanvasJobsTrait;

    /**
     * @var string
     */
    protected $signature = 'kanvas-inventory:enable-product-discovery
                            {app_id : App to set up}
                            {--company_id= : Set the flags for this company only, otherwise for the whole app}
                            {--enrichment= : on or off; omit to leave unchanged}
                            {--discovery= : on or off; omit to leave unchanged}';

    /**
     * @var string
     */
    protected $description = 'Prepare the app search index and turn product enrichment and semantic discovery on or off';

    public function handle(): int
    {
        /** @var Apps $app */
        $app = Apps::getById((int) $this->argument('app_id'));
        $this->overwriteAppService($app);

        try {
            $enrichment = $this->flag('enrichment');
            $discovery = $this->flag('discovery');
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $company = $this->option('company_id') !== null
            ? EnableProductDiscoveryAction::companyFor($app, (int) $this->option('company_id'))
            : null;

        $result = new EnableProductDiscoveryAction(
            app: $app,
            company: $company,
            enrichment: $enrichment,
            discovery: $discovery,
        )->execute();

        $this->info("Scope: {$result['scope']}, engine: {$result['engine']}");
        $this->line("Index: {$result['index']['status']}" . ($result['index']['message'] !== null ? ' - ' . $result['index']['message'] : ''));

        foreach ($result['index']['changes'] as $change) {
            $this->line('  + ' . $change);
        }

        foreach (['enrichment', 'discovery'] as $flag) {
            if ($result[$flag] !== null) {
                $this->line(ucfirst($flag) . ': ' . ($result[$flag] ? 'on' : 'off'));
            }
        }

        if ($result['backfill_queued'] > 0) {
            $this->line("Enrichment backfill queued for {$result['backfill_queued']} product(s).");
        }

        return $result['index']['status'] === EnableProductDiscoveryAction::INDEX_FAILED ? self::FAILURE : self::SUCCESS;
    }

    private function flag(string $option): ?bool
    {
        $value = $this->option($option);

        if ($value === null) {
            return null;
        }

        return match (strtolower((string) $value)) {
            'on' => true,
            'off' => false,
            default => throw new InvalidArgumentException("--{$option} takes \"on\" or \"off\"."),
        };
    }
}
