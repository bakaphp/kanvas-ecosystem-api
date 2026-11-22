<?php

declare(strict_types=1);

namespace App\Console\Commands\Ecosystem;

use App\Console\Commands\Ecosystem\Concerns\ResolvesAppKey;
use Baka\Traits\KanvasJobsTrait;
use Illuminate\Console\Command;
use Kanvas\Apps\Models\AppKey;
use Kanvas\Apps\Models\AppKeySource;
use Kanvas\Apps\Models\Apps;

class KanvasAppKeyWatchCommand extends Command
{
    use KanvasJobsTrait;
    use ResolvesAppKey;

    protected $signature = 'kanvas:app-key-watch
                            {app_id}
                            {--key= : client_id or client_secret_id of the key}
                            {--name= : name of the key}
                            {--off : stop alerting on new sources}
                            {--sources : list the sources the key was used from and exit}';

    protected $description = 'Alert when a Kanvas App Key is used from an IP it was never used from before';

    public function handle(): int
    {
        $app = Apps::getById((int) $this->argument('app_id'));
        $this->overwriteAppService($app);

        $key = $this->resolveKey($app);

        if ($key === null) {
            $this->error('No active app key found for app ' . $app->getId() . ' with the given --key/--name.');

            return self::FAILURE;
        }

        if ($this->option('sources')) {
            $this->listSources($key);

            return self::SUCCESS;
        }

        $key->alert_on_new_source = ! $this->option('off');
        $key->saveOrFail();

        $this->info(sprintf(
            'App key "%s" %s on new sources.',
            $key->name,
            $key->alert_on_new_source ? 'now alerts' : 'no longer alerts'
        ));

        return self::SUCCESS;
    }

    private function listSources(AppKey $key): void
    {
        $sources = AppKeySource::query()
            ->where('client_id', $key->client_id)
            ->orderByDesc('last_seen_at')
            ->get();

        $this->table(
            ['ip', 'user agent', 'first seen', 'last seen'],
            $sources->map(fn (AppKeySource $source) => [
                $source->ip,
                $source->user_agent ?? '-',
                $source->first_seen_at,
                $source->last_seen_at,
            ])->all()
        );
    }
}
