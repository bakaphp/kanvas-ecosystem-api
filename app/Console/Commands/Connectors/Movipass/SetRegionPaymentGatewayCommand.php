<?php

declare(strict_types=1);

namespace App\Console\Commands\Connectors\Movipass;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Console\Command;
use Kanvas\Apps\Models\Apps;
use Kanvas\Regions\Models\Regions;

use function Laravel\Prompts\error;
use function Laravel\Prompts\info;

class SetRegionPaymentGatewayCommand extends Command
{
    use KanvasJobsTrait;

    protected $signature = 'kanvas-movipass:set-region-gateway
        {app_id : The app ID that owns the region}
        {region : Region slug or short_slug (e.g. SV / el-salvador)}
        {gateway : Default payment gateway to set (e.g. payway)}
        {--gateways=* : Allowed gateways list; defaults to just {gateway}}';

    protected $description = 'Set the payment gateway of a global region via config (no full re-seed). Only touches the target region.';

    public function handle(): int
    {
        /** @var Apps $app */
        $app = Apps::getById((int) $this->argument('app_id'));
        $this->overwriteAppService($app);

        $regionRef = (string) $this->argument('region');
        $gateway = (string) $this->argument('gateway');
        $gateways = $this->option('gateways') ?: [$gateway];

        $region = Regions::query()
            ->fromApp($app)
            ->notDeleted()
            ->where('companies_id', 0)
            ->where(
                fn ($q) => $q->where('short_slug', $regionRef)->orWhere('slug', $regionRef)
            )
            ->first();

        if ($region === null) {
            error(sprintf('Global region %s not found in app %d', $regionRef, $app->getId()));

            return self::FAILURE;
        }

        $settings = $region->settings ?? [];
        $settings['default_payment_gateway'] = $gateway;
        $settings['payment_gateways'] = array_values(array_unique($gateways));
        $region->settings = $settings;
        $region->save();

        info(sprintf(
            'Region %s (%s): default_payment_gateway=%s, payment_gateways=%s',
            $region->name,
            $region->short_slug,
            $gateway,
            implode(',', $settings['payment_gateways']),
        ));

        return self::SUCCESS;
    }
}
