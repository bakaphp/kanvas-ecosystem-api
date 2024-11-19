<?php

declare(strict_types=1);

namespace App\Console\Commands\Setup;

use Illuminate\Console\Command;
use Kanvas\Apps\Repositories\AppsRepository;
use Baka\Traits\KanvasJobsTrait;

class FilesystemSetupCommand extends Command
{
    use KanvasJobsTrait;
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'kanvas:filesystem-setup {app_uuid?}';

    /**
     * The console command description.
     *
     * @var string|null
     */
    protected $description = 'Command that setup the filesystem disk';

    public function handle(): void
    {
        $appId = $this->argument('app_uuid') ?? config('kanvas.app.id');
        $app = AppsRepository::findFirstByKey($appId);
        $this->overwriteAppService($app);
        $app->set('filesystem-service', 's3');
        $app->set('cloud-bucket', config('filesystems.disks.s3.bucket'));
        $app->set('cloud-cdn', config('filesystems.disks.s3.url'));
        $app->set('cloud-bucket-path', config('filesystems.disks.s3.path'));
        $app->set('service-account-file', $this->createConfigFile());

        $this->info('Filesystem disk setup completed for app ' . $app->name . ' - ' . $app->uuid);
        $this->newLine();
    }

    /**
     * Create a config file for the setup in testing command.
     */
    public function createConfigFile(): array
    {
        return [
            'key' => config('filesystems.disks.s3.key'),
            'secret' => config('filesystems.disks.s3.secret'),
            'region' => config('filesystems.disks.s3.region'),
        ];
    }
}
