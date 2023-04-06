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
        $app->set('cloud-bucket', env('AWS_BUCKET'));
        $app->set('service-account-file', $this->createConfigFile());
    }

    public function createConfigFile()
    {
        return [
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION'),
        ];
    }
}
