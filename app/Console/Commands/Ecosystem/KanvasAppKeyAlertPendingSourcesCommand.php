<?php

declare(strict_types=1);

namespace App\Console\Commands\Ecosystem;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Console\Command;
use Kanvas\Apps\Actions\AlertAppKeyNewSourceAction;
use Kanvas\Apps\Models\AppKey;
use Kanvas\Apps\Models\AppKeySource;

/**
 * The request that first sees a new source raises its alert, and a failed one is retried when that
 * source comes back. A source used once never comes back, so this delivers what is still owed.
 */
class KanvasAppKeyAlertPendingSourcesCommand extends Command
{
    use KanvasJobsTrait;

    protected $signature = 'kanvas:app-key-alert-pending-sources';

    protected $description = 'Deliver new-source alerts for app keys that could not be sent when the source was first seen';

    public function handle(): int
    {
        $delivered = 0;
        $owed = 0;

        AppKeySource::query()->withPendingAlert()->chunkById(
            200,
            function ($sources) use (&$delivered, &$owed): void {
                foreach ($sources as $source) {
                    $key = AppKey::notDeleted()->where('client_id', $source->client_id)->first();

                    if ($key === null || ! $key->alert_on_new_source) {
                        $source->update([
                            'sentry_alert_pending' => false,
                            'email_alert_pending' => false,
                        ]);

                        continue;
                    }

                    $this->overwriteAppService($key->app);

                    new AlertAppKeyNewSourceAction($key, $source)->execute() ? $delivered++ : $owed++;
                }
            }
        );

        $this->info("Delivered {$delivered} pending app key alerts, {$owed} still owed.");

        return self::SUCCESS;
    }
}
