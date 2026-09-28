<?php

declare(strict_types=1);

namespace App\Console\Commands\Ecosystem\Imports;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Console\Command;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Companies\Models\CompaniesBranches;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Filesystem\Models\FilesystemMapper;
use Kanvas\Imports\Actions\CreateImportSourceAction;
use Kanvas\Imports\Actions\CreateMapperFromTemplateAction;
use Kanvas\Imports\DataTransferObject\ImportSourceData;
use Kanvas\Imports\DataTransferObject\ImportTemplate;
use Kanvas\Imports\DataTransferObject\MapperFromTemplate;
use Kanvas\Imports\Enums\ImportTemplateEnum;
use Kanvas\Imports\Models\ImportSource;
use Kanvas\Users\Models\Users;

/**
 * Onboards a company onto a predefined import template: creates (or reuses) its mapper and, when
 * --connection is given, the scheduled import that pulls the files every night.
 */
class SetupImportFromTemplateCommand extends Command
{
    use KanvasJobsTrait;

    protected $signature = 'kanvas:imports:setup-from-template
                            {template : Template key, e.g. dealer_vehicle_csv}
                            {app_id}
                            {company_id}
                            {--option=* : Template option as name=choice, e.g. price_source=price_first}
                            {--user-id= : Run-as user; defaults to the company owner}
                            {--connection= : Import connection id; creates the scheduled import too}
                            {--file=* : Remote file, optionally with a template filter and/or "optional", e.g. "MP22154.csv|Used only"}
                            {--warehouse= : Warehouse id the rows go to}
                            {--channel= : Channel id the rows are published in}
                            {--region= : Region id; defaults to the company default region}
                            {--name= : Scheduled import name}
                            {--root= : Remote folder; defaults to the connection folder}
                            {--schedule= : Cron; defaults to the connection default}
                            {--timezone= : Defaults to the connection timezone}';

    protected $description = 'Set up a company import from a predefined template (mapper + optional scheduled import)';

    public function handle(): int
    {
        $templateCase = ImportTemplateEnum::tryFrom((string) $this->argument('template'));
        if ($templateCase === null) {
            $this->error('Unknown template. Available: ' . implode(', ', array_column(ImportTemplateEnum::cases(), 'value')));

            return self::FAILURE;
        }

        /** @var Apps $app */
        $app = Apps::getById((int) $this->argument('app_id'));
        $this->overwriteAppService($app);

        $company = Companies::getById((int) $this->argument('company_id'));
        $branch = $company->defaultBranch ?? $company->branch;
        if ($branch === null) {
            $this->error('Company ' . $company->getId() . ' has no branch.');

            return self::FAILURE;
        }

        $user = $this->option('user-id') ? Users::getById((int) $this->option('user-id')) : $company->user;
        $template = $templateCase->template();

        $mapper = new CreateMapperFromTemplateAction(
            new MapperFromTemplate(
                template: $template,
                app: $app,
                branch: $branch,
                user: $user,
                options: $this->parseOptions((array) $this->option('option')),
            )
        )->execute();

        $this->info(sprintf(
            '%s mapper #%d "%s" for company %d.',
            $mapper->wasRecentlyCreated ? 'Created' : 'Reused',
            $mapper->getId(),
            $mapper->name,
            $company->getId()
        ));

        if ($this->option('connection') === null) {
            return self::SUCCESS;
        }

        return $this->createSource(
            $template,
            $mapper,
            $branch,
            $user
        );
    }

    private function createSource(
        ImportTemplate $template,
        FilesystemMapper $mapper,
        CompaniesBranches $branch,
        Users $user
    ): int {
        $files = $this->parseFiles((array) $this->option('file'), $template);
        $existing = $this->existingSource($branch, $mapper, $files);

        $source = $existing ?? new CreateImportSourceAction(
            ImportSourceData::from(
                $mapper->app,
                $branch,
                [
                    ...$template->sourceInputDefaults(),
                    'users_id' => $user->getId(),
                    'import_connection_id' => (int) $this->option('connection'),
                    'regions_id' => $this->option('region'),
                    'warehouses_id' => $this->option('warehouse'),
                    'channels_id' => $this->option('channel'),
                    'name' => $this->option('name') ?? $branch->company->name . ' — ' . $template->name,
                    'files' => $files,
                    'root' => $this->option('root'),
                    'schedule' => $this->option('schedule'),
                    'timezone' => $this->option('timezone'),
                ],
                $mapper
            )
        )->execute();

        $this->info(sprintf(
            '%s scheduled import #%d "%s" (%s, %s).',
            $existing === null ? 'Created' : 'Reused',
            $source->getId(),
            $source->name,
            $source->effectiveSchedule() ?? 'no schedule — runs only when forced',
            $source->effectiveTimezone()
        ));

        return self::SUCCESS;
    }

    /**
     * Rerunning the setup for a company must not leave it with two identical nightly imports — the
     * rollout runs this command once per rooftop and a typo means running it again. A company that
     * genuinely pulls a second feed still gets its own source, because the file list differs.
     *
     * @param list<array{pattern: string, filter: array|null, required: bool}> $files
     */
    private function existingSource(CompaniesBranches $branch, FilesystemMapper $mapper, array $files): ?ImportSource
    {
        $patterns = array_column($files, 'pattern');
        sort($patterns);

        return ImportSource::query()
            ->where('companies_id', $branch->company->getId())
            ->where('filesystem_mapper_id', $mapper->getId())
            ->where('import_connections_id', (int) $this->option('connection'))
            ->notDeleted()
            ->get()
            ->first(function (ImportSource $source) use ($patterns): bool {
                $existing = array_column($source->files, 'pattern');
                sort($existing);

                return $existing === $patterns;
            });
    }

    /**
     * "MP22154.csv|Used only|optional" → pattern, the template's "Used only" filter, not required.
     *
     * @return list<array{pattern: string, filter: array|null, required: bool}>
     */
    private function parseFiles(array $specs, ImportTemplate $template): array
    {
        $presets = $template->sourceDefaults['file_filters'] ?? [];
        $files = [];

        foreach ($specs as $spec) {
            $parts = array_map('trim', explode('|', (string) $spec));
            $file = ['pattern' => array_shift($parts), 'filter' => null, 'required' => true];

            foreach ($parts as $modifier) {
                if (strcasecmp($modifier, 'optional') === 0) {
                    $file['required'] = false;
                } elseif (isset($presets[$modifier])) {
                    $file['filter'] = $presets[$modifier];
                } else {
                    throw new ValidationException(sprintf(
                        'Unknown file modifier "%s". Use "optional" or one of: %s',
                        $modifier,
                        implode(', ', array_keys($presets))
                    ));
                }
            }

            $files[] = $file;
        }

        return $files;
    }

    /**
     * @return array<string, string>
     */
    private function parseOptions(array $pairs): array
    {
        $options = [];
        foreach ($pairs as $pair) {
            [$name, $choice] = array_pad(explode('=', (string) $pair, 2), 2, '');
            $options[trim($name)] = trim($choice);
        }

        return $options;
    }
}
