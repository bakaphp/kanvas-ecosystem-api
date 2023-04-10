<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Bouncer;
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
        $app->set('cloud-bucket', config('filesystems.s3.bucket'));
        $app->set('service-account-file', $this->createConfigFile());
    }

    /**
     * Create a config file for the setup in testing command.
     *
     * @return array
     */
    public function createConfigFile(): array
    {
        return [
            'key' => config('filesystems.s3.disks.key'),
            'secret' => config('filesystems.s3.disks.secret'),
            'region' => config('filesystems.s3.disks.region'),
        ];
    }
}
