<?php

declare(strict_types=1);

namespace App\Console\Commands\Ecosystem\Notifications;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Kanvas\Apps\Models\Apps;
use Kanvas\Notifications\Mailings\Enums\AppUserMailingStatusEnum;
use Kanvas\Notifications\Mailings\Jobs\SendAppUsersEmailJob;
use Kanvas\Notifications\Mailings\Models\AppUserMailing;

class DispatchScheduledAppUserMailingsCommand extends Command
{
    protected $signature = 'kanvas:dispatch-scheduled-app-user-mailings';

    protected $description = 'Queue every approved app-user mailing whose scheduled time has arrived';

    public function handle(): int
    {
        $dispatched = 0;

        $due = AppUserMailing::query()
            ->notDeleted()
            ->where('status', AppUserMailingStatusEnum::SCHEDULED->value)
            ->where('scheduled_at', '<=', Carbon::now())
            ->orderBy('scheduled_at')
            ->get();

        foreach ($due as $mailing) {
            if (! $mailing->claimForDispatch()) {
                continue;
            }

            SendAppUsersEmailJob::dispatch(Apps::getById($mailing->apps_id), $mailing);
            $dispatched++;
        }

        $this->info("Dispatched {$dispatched} scheduled mailing(s).");

        return self::SUCCESS;
    }
}
