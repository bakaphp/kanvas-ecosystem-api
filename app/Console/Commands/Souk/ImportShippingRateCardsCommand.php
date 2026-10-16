<?php

declare(strict_types=1);

namespace App\Console\Commands\Souk;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Validation\ValidationException;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Souk\Shipping\RateCards\Actions\ImportRateCardsAction;
use Kanvas\Users\Models\UserCompanyApps;

class ImportShippingRateCardsCommand extends Command
{
    use KanvasJobsTrait;

    protected $signature = 'kanvas:shipping-import-rate-cards {app_id} {company_id} {file}';

    protected $description = 'Sync a company shipping rate cards from a JSON file';

    public function handle(): int
    {
        $app = Apps::getById((int) $this->argument('app_id'));
        $company = Companies::getById((int) $this->argument('company_id'));
        $this->overwriteAppService($app);

        $isAssociated = UserCompanyApps::query()
            ->where('apps_id', $app->getId())
            ->where('companies_id', $company->getId())
            ->exists();

        if (! $isAssociated) {
            $this->error("Company {$company->getId()} is not associated with app {$app->getId()}.");

            return self::FAILURE;
        }

        $path = $this->resolvePath((string) $this->argument('file'));

        if (! is_file($path)) {
            $this->error("File not found: {$path}");

            return self::FAILURE;
        }

        $rateCards = json_decode(File::get($path), true);

        if (! is_array($rateCards)) {
            $this->error('The file does not contain a valid JSON object.');

            return self::FAILURE;
        }

        try {
            $counts = new ImportRateCardsAction($app, $company, $rateCards)->execute();
        } catch (ValidationException $exception) {
            foreach ($exception->errors() as $messages) {
                foreach ($messages as $message) {
                    $this->error($message);
                }
            }

            return self::FAILURE;
        }

        foreach ($counts as $metric => $count) {
            $this->line("{$metric}: {$count}");
        }

        return self::SUCCESS;
    }

    private function resolvePath(string $file): string
    {
        $isAbsolute = preg_match('#^([A-Za-z]:[\\\\/]|[\\\\/])#', $file) === 1;

        return $isAbsolute ? $file : base_path($file);
    }
}
