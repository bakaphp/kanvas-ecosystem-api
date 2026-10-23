<?php

declare(strict_types=1);

namespace App\Console\Commands\Insurance;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Kanvas\Apps\Models\Apps;
use Kanvas\Insurance\Actions\SendInsurancePolicyRemindersAction;
use Kanvas\Insurance\Enums\InsuranceOrderTypeEnum;
use Kanvas\Souk\Orders\Models\OrderTypes;

class SendInsurancePolicyRemindersCommand extends Command
{
    use KanvasJobsTrait;

    protected $signature = 'kanvas:insurance-send-policy-reminders {--app= : Restrict to one app id} {--date= : Simulate today (YYYY-MM-DD)}';

    protected $description = 'Remind users before their insurance policy expires (idempotent per policy end date and day offset)';

    public function handle(): int
    {
        $appIds = OrderTypes::query()
            ->where('name', InsuranceOrderTypeEnum::INSURANCE->value)
            ->when($this->option('app'), fn ($query, $appId) => $query->where('apps_id', (int) $appId))
            ->pluck('apps_id')
            ->unique();

        foreach ($appIds as $appId) {
            $app = Apps::getById((int) $appId);
            $this->overwriteAppService($app);

            $today = $this->option('date')
                ? Carbon::parse($this->option('date'))->startOfDay()
                : Carbon::now($app->get('timezone') ?: null)->startOfDay();
            $sent = new SendInsurancePolicyRemindersAction($app, $today)->execute();

            $this->info('App ' . $app->getId() . ': ' . $sent . ' policy reminders sent for ' . $today->toDateString());
        }

        return self::SUCCESS;
    }
}
