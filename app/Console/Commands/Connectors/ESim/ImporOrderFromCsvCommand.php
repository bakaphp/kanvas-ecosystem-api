<?php
declare(strict_types=1);
namespace App\Console\Commands\Connectors\ESim;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Console\Command;
use Kanvas\Apps\Models\Apps;
use Kanvas\Regions\Models\Regions;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\ESim\Actions\ImportOrderFromCsvAction;
use Kanvas\Users\Repositories\UsersRepository;

class ImporOrderFromCsvCommand extends Command
{
    use KanvasJobsTrait;
    protected $signature = "kanvas:import-order-from-csv {app_id} {company_id} {region_id} {user_id} {url}";


    public function handle(): void
    {
        $app = Apps::getById($this->argument("app_id"));
        $company = Companies::getById($this->argument("company_id"));
        $region = Regions::getById($this->argument("region_id"));
        $user = UsersRepository::getUserOfAppById((int)$this->argument("user_id"), $app);
        $url = $this->argument("url");
        (new ImportOrderFromCsvAction(
            $app,
            $company,
            $region,
            $user,
            $url
        )
        )->execute();
    }
}
