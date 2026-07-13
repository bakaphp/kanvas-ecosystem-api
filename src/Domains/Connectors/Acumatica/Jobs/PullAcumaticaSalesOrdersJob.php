<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Acumatica\Jobs;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Acumatica\Actions\PullSalesOrdersAction;
use Kanvas\Regions\Models\Regions;
use Kanvas\Users\Models\Users;

final class PullAcumaticaSalesOrdersJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use KanvasJobsTrait;
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly Apps $app,
        public readonly Companies $company,
        public readonly Users $user,
        public readonly Regions $region,
        public readonly int $acumaticaCompanyId,
    ) {
    }

    /**
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [
            new WithoutOverlapping('acumatica-orders-' . (string) $this->company->getId()),
        ];
    }

    public function handle(): void
    {
        $this->overwriteAppService($this->app);

        new PullSalesOrdersAction(
            $this->app,
            $this->company,
            $this->user,
            $this->region,
            $this->acumaticaCompanyId,
        )->execute();
    }
}
