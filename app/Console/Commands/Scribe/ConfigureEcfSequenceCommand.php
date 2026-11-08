<?php

declare(strict_types=1);

namespace App\Console\Commands\Scribe;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Console\Command;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Scribe\ElectronicInvoicing\Actions\ConfigureEcfRangeAction;
use Throwable;

class ConfigureEcfSequenceCommand extends Command
{
    use KanvasJobsTrait;

    protected $signature = 'kanvas-scribe:ecf-sequence {app_id} {company_id} {type} {start} {end} {expires_at?}';

    protected $description = 'Configure the authorized e-NCF range (start, end, expiry) for one e-CF type of a company';

    public function handle(): int
    {
        $app = Apps::getById((int) $this->argument('app_id'));
        $this->overwriteAppService($app);

        $company = Companies::getById((int) $this->argument('company_id'));

        $action = new ConfigureEcfRangeAction(
            app: $app,
            company: $company,
            type: (string) $this->argument('type'),
            start: (string) $this->argument('start'),
            end: (string) $this->argument('end'),
            expiresAt: $this->argument('expires_at'),
        );

        try {
            $sequence = $action->execute();
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if ($action->notice !== null) {
            $this->warn($action->notice);
        }

        $this->info(sprintf(
            '%s range %s – %s, expires %s. Next e-NCF: %s (%d left).',
            $sequence->prefix,
            $sequence->format((int) $sequence->range_start),
            $sequence->format((int) $sequence->range_end),
            $sequence->expires_at?->toDateString() ?? 'never',
            $sequence->format($sequence->next_value),
            (int) $sequence->remaining(),
        ));

        return self::SUCCESS;
    }
}
