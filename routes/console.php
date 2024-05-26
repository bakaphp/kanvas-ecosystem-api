<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Kanvas\Apps\Repositories\AppsRepository;
use Illuminate\Support\Facades\Schedule;
use Kanvas\Connectors\Jobs\MailCaddieLabJob;

/*
|--------------------------------------------------------------------------
| Console Routes
|--------------------------------------------------------------------------
|
| This file is where you may define all of your Closure based console
| commands. Each Closure is bound to a command instance allowing a
| simple approach to interacting with each command's IO methods.
|
*/

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::job(new MailCaddieLabJob(AppsRepository::findFirstByKey(getenv('CADDIE_APP_KEY'))))
        ->everySecond();

