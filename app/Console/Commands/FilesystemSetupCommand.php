<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Kanvas\Apps\Repositories\AppsRepository;

class FilesystemSetupCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'filesystem:disk';

    /**
     * The console command description.
     *
     * @var string|null
     */
    protected $description = 'Command that setup the filesystem disk';

    public function handle(): void
    {
        $app = AppsRepository::findFirstByKey(env('KANVAS_APP_ID'));

        $app->set('filesystem-service', 's3');
        $app->set('cloud-bucket', config('filesystems.disks.s3.bucket'));
        $app->set('cloud-cdn', config('filesystems.disks.s3.url'));
        $app->set('service-account-file', $this->createConfigFile());
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
