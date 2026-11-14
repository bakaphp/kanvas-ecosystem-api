<?php

declare(strict_types=1);

namespace App\Console\Commands\Connectors\WaSender;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Kanvas\Apps\Models\Apps;
use Kanvas\Communication\Connections\Actions\EnsureProviderIntegrationAction;
use Kanvas\Connectors\WaSender\Handlers\WaSenderHandler;

/**
 * One-off for the cutover: a number linked by QR before WaSender was a Communication provider has
 * a receiver but no integration row, and without the row no connection is ever set up for it.
 */
class AdoptCommunicationCommand extends Command
{
    use KanvasJobsTrait;

    protected $signature = 'kanvas:wasender:adopt-communication {app_id} {--company=}';

    protected $description = 'Give every company with a WaSender receiver the integration row '
        . 'Communication sets its connection up from.';

    public function handle(): int
    {
        $app = Apps::getById((int) $this->argument('app_id'));
        $this->overwriteAppService($app);

        $receivers = WaSenderHandler::receivers()
            ->fromApp($app)
            ->when(
                $this->option('company'),
                fn (Builder $query, string $company) => $query->fromCompany((int) $company)
            )
            ->get()
            ->unique('companies_id');

        foreach ($receivers as $receiver) {
            $selection = WaSenderHandler::selectionFromReceiver($receiver);
            $row = new EnsureProviderIntegrationAction(
                $app,
                $receiver->company,
                $receiver->user ?? $receiver->company->user,
                WaSenderHandler::class,
                $selection
            )->execute();

            $this->line(sprintf(
                '  company=%d receiver=%d %s%s',
                $receiver->companies_id,
                $receiver->getId(),
                $row === null
                    ? 'skipped (no WaSender integration in the catalog, or no region)'
                    : 'integration=' . $row->getId(),
                isset($selection['phone_number'])
                    ? ' number=' . $selection['phone_number']
                    : ' NO NUMBER: set the agent\'s WhatsApp number, then run communication:setup'
            ));
        }

        $this->info('Run communication:setup for this app to turn each number into a connection.');

        return self::SUCCESS;
    }
}
