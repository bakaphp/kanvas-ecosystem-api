<?php

declare(strict_types=1);

namespace App\Console\Commands\Connectors\WebPos;

use Illuminate\Console\Command;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\WebPos\Actions\PullReceivedEcfsAction;
use Kanvas\Connectors\WebPos\Enums\ConfigurationEnum;
use Kanvas\Connectors\WebPos\Jobs\PullReceivedEcfsJob;
use Kanvas\Scribe\ElectronicInvoicing\Enums\ConfigurationEnum as EcfConfigurationEnum;
use Kanvas\Scribe\ElectronicInvoicing\Enums\EcfProviderEnum;

/**
 * Pure dispatcher: one job per company whose e-CF provider is WebPOS. Each job rebinds its own app.
 */
class PullWebPosReceivedEcfsCommand extends Command
{
    protected $signature = 'kanvas:webpos-pull-received
                            {--company_id= : Pull one company instead of every WebPOS company}
                            {--lookback=7 : Days back to ask WebPOS for (max 31)}';

    protected $description = 'Queue the pull of vendor e-CFs received through WebPOS into draft bills.';

    public function handle(): int
    {
        $lookback = min((int) $this->option('lookback'), PullReceivedEcfsAction::MAX_WINDOW_DAYS);
        $query = Companies::getByCustomFieldBuilder(EcfConfigurationEnum::ECF_PROVIDER->value, EcfProviderEnum::WEBPOS->value);

        if ($this->option('company_id')) {
            $query->where('companies.id', (int) $this->option('company_id'));
        }

        $dispatched = 0;

        foreach ($query->get() as $company) {
            /** @var Companies $company */
            $appId = (int) $company->get(ConfigurationEnum::APP_ID->value);

            if ($appId <= 0) {
                $this->warn("company {$company->getId()}: no WebPOS app id; run kanvas:webpos-setup.");

                continue;
            }

            PullReceivedEcfsJob::dispatch(Apps::getById($appId), $company, $lookback);
            $dispatched++;
        }

        $this->info("Queued {$dispatched} WebPOS reception pull(s).");

        return self::SUCCESS;
    }
}
