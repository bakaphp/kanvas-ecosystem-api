<?php

declare(strict_types=1);

namespace App\Console\Commands\Connectors\Apollo;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Console\Command;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Apollo\Enums\ConfigurationEnum;
use Kanvas\Guild\Customers\Models\People;

class ReportJobChangesCommand extends Command
{
    use KanvasJobsTrait;

    /**
     * @var string
     */
    protected $signature = 'kanvas:guild-apollo-changes-report {app_id} {company_id} {--from= : Only changes on/after this date (Y-m-d)} {--to= : Only changes on/before this date (Y-m-d)} {--csv : Emit raw CSV instead of a table}';

    /**
     * @var string|null
     */
    protected $description = 'List the people whose current employer changed during Apollo enrichment (old → new job)';

    public function handle(): int
    {
        $app = Apps::getById((int) $this->argument('app_id'));
        $this->overwriteAppService($app);
        $company = Companies::getById((int) $this->argument('company_id'));

        $from = trim((string) $this->option('from'));
        $to = trim((string) $this->option('to'));
        $csv = (bool) $this->option('csv');

        $query = People::getByCustomFieldBuilder(ConfigurationEnum::APOLLO_JOB_CHANGED_AT->value, null, $company)
            ->fromApp($app)
            ->notDeleted(0);

        if ($from !== '') {
            $query->where('apps_custom_fields.value', '>=', strtotime($from));
        }

        if ($to !== '') {
            $query->where('apps_custom_fields.value', '<=', strtotime($to . ' 23:59:59'));
        }

        $people = $query->orderBy('apps_custom_fields.value', 'desc')->get();

        $rows = [];
        foreach ($people as $person) {
            $change = (array) ($person->get(ConfigurationEnum::APOLLO_LAST_JOB_CHANGE->value) ?? []);

            $rows[] = [
                'people_id' => $person->id,
                'name' => trim("{$person->firstname} {$person->lastname}"),
                'from_company' => $change['from_company'] ?? '',
                'from_title' => $change['from_title'] ?? '',
                'to_company' => $change['to_company'] ?? '',
                'to_title' => $change['to_title'] ?? '',
                'changed_at' => $change['changed_at'] ?? '',
            ];
        }

        if ($csv) {
            $this->line('people_id,name,from_company,from_title,to_company,to_title,changed_at');
            foreach ($rows as $row) {
                $this->line(implode(',', array_map($this->csvCell(...), $row)));
            }

            return self::SUCCESS;
        }

        $this->line("Job changes for company {$company->name} from app {$app->name}: {$people->count()} people");
        $this->table(
            ['ID', 'Name', 'From Company', 'From Title', 'To Company', 'To Title', 'Changed At'],
            $rows
        );

        return self::SUCCESS;
    }

    private function csvCell(int|string $value): string
    {
        $value = (string) $value;
        if (preg_match('/[",\n]/', $value)) {
            return '"' . str_replace('"', '""', $value) . '"';
        }

        return $value;
    }
}
