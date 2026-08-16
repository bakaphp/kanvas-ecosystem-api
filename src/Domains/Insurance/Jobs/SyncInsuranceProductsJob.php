<?php

declare(strict_types=1);

namespace Kanvas\Insurance\Jobs;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Insurance\Actions\SyncInsuranceProductsAction;
use Kanvas\Insurance\Contracts\ProductCatalogProviderInterface;
use Kanvas\Insurance\Enums\InsuranceCustomFieldEnum;
use Kanvas\Insurance\Providers\InsuranceProviderFactory;

/**
 * Runs off the back of a successful integration setup so the aliado has a usable
 * catalog the moment credentials land, without making setup wait on it.
 */
final class SyncInsuranceProductsJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use KanvasJobsTrait;
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly Apps $app,
        public readonly Companies $company,
        public readonly string $providerName,
    ) {
    }

    public function handle(): void
    {
        $this->overwriteAppService($this->app);

        $provider = InsuranceProviderFactory::make($this->providerName, $this->app, $this->company);

        if (! $provider instanceof ProductCatalogProviderInterface) {
            return;
        }

        $insurerCompanyId = (int) $this->company->get(InsuranceCustomFieldEnum::INSURER_COMPANY_ID->value);

        if ($insurerCompanyId === 0) {
            return;
        }

        new SyncInsuranceProductsAction(
            $provider,
            $this->app,
            Companies::getById($insurerCompanyId),
        )->execute();
    }
}
