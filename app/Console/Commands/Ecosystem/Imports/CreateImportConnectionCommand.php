<?php

declare(strict_types=1);

namespace App\Console\Commands\Ecosystem\Imports;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Console\Command;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Imports\Actions\CreateImportConnectionAction;
use Kanvas\Imports\DataTransferObject\ImportConnectionData;
use Kanvas\Users\Models\Users;

class CreateImportConnectionCommand extends Command
{
    use KanvasJobsTrait;

    protected $signature = 'kanvas:imports:create-connection
                            {app_id}
                            {--company=0 : Company id; 0 makes it app-wide (usable by every company)}
                            {--name=}
                            {--driver=ftp : ftp or sftp}
                            {--host=}
                            {--port= : Defaults to 21 (ftp) or 22 (sftp)}
                            {--username=}
                            {--password= : Asked for when omitted, so it stays out of shell history}
                            {--root= : Folder the files are in}
                            {--schedule= : Default cron for imports using it, e.g. "0 1 * * *"}
                            {--timezone= : e.g. America/New_York}
                            {--user-id= : Owner; defaults to the company owner, required when app-wide}';

    protected $description = 'Create an FTP/SFTP connection that scheduled imports download from';

    public function handle(): int
    {
        /** @var Apps $app */
        $app = Apps::getById((int) $this->argument('app_id'));
        $this->overwriteAppService($app);

        $companyId = (int) $this->option('company');
        $company = $companyId > 0 ? Companies::getById($companyId) : null;
        $user = $this->option('user-id') ? Users::getById((int) $this->option('user-id')) : $company?->user;
        if ($user === null) {
            $this->error('--user-id is required for an app-wide connection');

            return self::FAILURE;
        }

        $connection = new CreateImportConnectionAction(
            ImportConnectionData::from(
                $app,
                $company,
                $user,
                [
                    'name' => $this->option('name'),
                    'driver' => $this->option('driver'),
                    'host' => $this->option('host'),
                    'port' => $this->option('port'),
                    'username' => $this->option('username'),
                    'password' => $this->option('password') ?? $this->secret('Password'),
                    'root' => $this->option('root'),
                    'default_schedule' => $this->option('schedule'),
                    'timezone' => $this->option('timezone'),
                ]
            )
        )->execute();

        $this->info(sprintf(
            'Created %s connection #%d "%s"%s.',
            $connection->driver->value,
            $connection->getId(),
            $connection->name,
            $connection->isAppWide() ? ' (app-wide)' : ' for company ' . $connection->companies_id
        ));

        return self::SUCCESS;
    }
}
