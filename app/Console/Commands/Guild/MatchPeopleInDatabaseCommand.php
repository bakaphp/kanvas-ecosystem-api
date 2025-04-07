<?php

declare(strict_types=1);

namespace App\Console\Commands\Guild;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use League\Csv\Reader;

class MatchPeopleInDatabaseCommand extends Command
{
    use KanvasJobsTrait;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'kanvas-guild:match-people {apps_id} {company_id} {file}';

    /**
     * The console command description.
     *
     * @var string|null
     */
    protected $description = 'Match people in the database with the csv file';

    public function handle(): void
    {
        $app = Apps::getById((int) $this->argument('apps_id'));
        $this->overwriteAppService($app);

        $company = Companies::getById((int) $this->argument('company_id'));
        $file = $this->argument('file');

        //read csv file
        $csv = Reader::createFromPath($file, 'r');

        $csv->setHeaderOffset(0);

        $header = $csv->getHeader(); //returns the CSV header record
        $records = $csv->getRecords(); //returns all the CSV records as an Iterator object

        foreach ($records as $record) {
            $organization = $record['Company'];
            $name = $record['Attendee Full Name'];
            $title = $record['Title'];

            // Split full name into first name and last name
            $names = explode(' ', $name);
            $firstName = $names[0];
            $lastName = isset($names[1]) ? $names[1] : '';

            // Construct the regular expression to allow slight variations
            $regexPattern = $firstName . '[^ ]*.*' . $lastName . '[^ ]*';

            $result = DB::connection('crm')->select('
                SELECT p.id, p.name AS full_name
                FROM peoples p
                WHERE p.name REGEXP ?
                AND p.apps_id = ?
                AND p.companies_id = ?
            ', [
                $regexPattern,
                $app->getId(),
                $company->getId(),
            ]);

            if ($result) {
                $this->info('Match Found Processing ' . $name);
            } else {
                $this->info('No match Processing ' . $name);
            }
        }
    }
}
