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
use Kanvas\Insurance\Actions\BuildVehicleModelCrosswalkAction;
use Kanvas\Insurance\Actions\ListCanonicalVehiclesAction;
use Kanvas\Insurance\Contracts\TargetedVehicleCatalogProviderInterface;
use Kanvas\Insurance\Contracts\VehicleModelCatalogProviderInterface;
use Kanvas\Insurance\Enums\InsuranceCatalogStatusEnum;
use Kanvas\Insurance\Models\Insurer;
use Kanvas\Insurance\Providers\InsuranceProviderFactory;
use Throwable;

final class BuildVehicleModelCrosswalkJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use KanvasJobsTrait;
    use Queueable;
    use SerializesModels;

    public int $timeout = 1800;

    public int $tries = 1;

    public function __construct(
        public readonly Apps $app,
        public readonly Companies $company,
    ) {
    }

    public function handle(): void
    {
        $this->overwriteAppService($this->app);

        $vehicles = new ListCanonicalVehiclesAction($this->app)->execute();

        $insurers = Insurer::query()
            ->fromApp($this->app)
            ->notDeleted()
            ->where('status', InsuranceCatalogStatusEnum::ACTIVE->value)
            ->whereNotNull('provider')
            ->get();

        foreach ($insurers as $insurer) {
            try {
                $provider = InsuranceProviderFactory::make($insurer->provider, $this->app, $this->company);
            } catch (Throwable $exception) {
                report($exception);

                continue;
            }

            if (
                ! $provider instanceof VehicleModelCatalogProviderInterface
                && ! $provider instanceof TargetedVehicleCatalogProviderInterface
            ) {
                continue;
            }

            new BuildVehicleModelCrosswalkAction($insurer, $provider, $vehicles)->execute();
        }
    }
}
