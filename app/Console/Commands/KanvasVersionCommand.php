<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Console\Command;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Credit700\DataTransferObject\CreditApplicant;
use Kanvas\Connectors\Credit700\Services\CreditScoreService;
use Kanvas\Connectors\Credit700\Support\Setup;
use Kanvas\Enums\AppEnums;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Social\Channels\Models\Channel;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\SystemModules\Models\SystemModules;

class KanvasVersionCommand extends Command
{
    use KanvasJobsTrait;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'kanvas:version';

    /**
     * The console command description.
     *
     * @var string|null
     */
    protected $description = 'Whats the current version of kanvas niche you are running';

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        $this->newLine();
        $this->info('Kanvas Niche is running version : ' . AppEnums::VERSION->getValue());
        $this->newLine();

        $app = Apps::getById(78);
        $this->overwriteAppService($app);

        $order = Order::getById(768);
        echo $order->get('message_id');

        die();

        $leadChannel = Channel::fromApp($app)
            ->where('entity_id', 24076)
            ->whereIn('entity_namespace', [Lead::class, SystemModules::getLegacyNamespace(Lead::class)])
            ->firstOrFail();

        print_r($leadChannel);
        die();

        $setup700Credit = new Setup($app);
        $setup700Credit->run();

        $lead = Lead::getById(24076);
        $people = $lead->people;
        $address = $people->address()->first();

        $creditApplication = new CreditApplicant(
            'Mcintyre S Benjamin', //$people->name,
            '718 Jefferson ', //$address->address,
            'Fort Wayne', //$address->city,
            'AL', //$address->state,
            '35080', //$address->zip,
            '666271746', //fake()->ssn
        );

        $creditScoreAction = new CreditScoreService($app);
        $creditScore = $creditScoreAction->getCreditScore($creditApplication, $lead->user);

        print_r($creditScore);
        $lead->addFileFromUrl($creditScore['iframe_url_signed'], 'credit_score_report.pdf');
        die();

        return;
    }
}
