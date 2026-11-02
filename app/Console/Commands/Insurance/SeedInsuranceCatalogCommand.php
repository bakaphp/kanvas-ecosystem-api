<?php

declare(strict_types=1);

namespace App\Console\Commands\Insurance;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Exceptions\ModelNotFoundException as KanvasModelNotFoundException;
use Kanvas\Insurance\Actions\SeedInsuranceCatalogAction;

class SeedInsuranceCatalogCommand extends Command
{
    use KanvasJobsTrait;

    protected $signature = 'kanvas:insurance-seed-catalog {--app_id=} {--company_id=}';

    protected $description = 'Seed the Universal Seguros and Humano insurers and their plans, never rewriting existing rows';

    public function handle(): int
    {
        $apps = $this->option('app_id') ? Apps::where('id', $this->option('app_id'))->get() : Apps::all();

        foreach ($apps as $app) {
            $this->overwriteAppService($app);

            try {
                $company = $this->option('company_id')
                    ? Companies::getById((int) $this->option('company_id'))
                    : $app->getAppCompany();
            } catch (ModelNotFoundException | KanvasModelNotFoundException) {
                $this->warn('App ' . $app->getId() . ' has no company to own the catalog, skipping');

                continue;
            }

            $created = new SeedInsuranceCatalogAction($app, $company)->execute();

            $this->info('App ' . $app->getId() . ': ' . count($created) . ' plans created');
        }

        return self::SUCCESS;
    }
}
