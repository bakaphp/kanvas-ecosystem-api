<?php

declare(strict_types=1);

namespace App\Console\Commands\Intelligence;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Console\Command;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Enums\ConfigurationEnum as CompanyConfigurationEnum;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Elead\Entities\SalesActivities;
use Kanvas\Connectors\Elead\Enums\CustomFieldEnum;
use Kanvas\Guild\Leads\Actions\SendMessageToLeadAction;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Social\Messages\Models\Message;

class SendDelayMessageCommand extends Command
{
    use KanvasJobsTrait;

    protected $signature = 'kanvas:intelligence:send-delay-message {app_id}';

    public function handle(): void
    {
        $app = Apps::getById((int) $this->argument('app_id'));
        $this->overwriteAppService($app);

        $companies = Companies::getByCustomField(CompanyConfigurationEnum::MESSAGE_MINUTES_INTERVAL->value, null);

        foreach ($companies as $company) {
            $this->newLine();
            $minutedMessages = $company->get(CompanyConfigurationEnum::MESSAGE_MINUTES_INTERVAL->value) ?? 60;
            $messages = Message::where('apps_id', $app->getId())
                ->whereIn('companies_id', $company->getId())
                ->where('is_locked', true)
                ->whereRaw("DATE_ADD(created_at, INTERVAL {$minutedMessages} MINUTE) <= NOW()")
                ->cursor();

            foreach ($messages as $message) {
                $communicationChannel = $message->get('communicationChannel');
                $fromNumber = $message->get('from_number');
                $title = $message->get('title');
                $lead = $message->entity;

                if (! $lead instanceof Lead) {
                    $this->error('Message ID ' . $message->getId() . ' is not linked to a Lead entity.');

                    continue;
                }

                // for now only work with elead, missing determining if lead was contacted
                if (empty($lead->get(CustomFieldEnum::OPPORTUNITY_ID->value))) {
                    $this->info('Lead ID ' . $lead->getId() . ' does not have an Opportunity ID. Skipping message ID ' . $message->getId() . '.');
                    $message->is_locked = false;
                    $message->saveOrFail();

                    continue;
                }

                if (SalesActivities::hasSalesAgentReachedOut(
                    $lead->app,
                    $lead->company,
                    $lead->people->get(CustomFieldEnum::CUSTOMER_ID->value),
                    $lead->get(CustomFieldEnum::OPPORTUNITY_ID->value)
                )) {
                    $message->is_locked = false;
                    $message->saveOrFail();
                    $this->info('Lead ID ' . $lead->getId() . ' has already been contacted by sales agent. Skipping message ID ' . $message->getId() . '.');

                    continue;
                }

                new SendMessageToLeadAction($lead)->execute(
                    $communicationChannel,
                    $message->message,
                    $fromNumber,
                    $title,
                );
                $message->is_locked = false;
                $message->saveOrFail();
            }
            $this->info('Processed messages for company: ' . $company->name);
        }
    }
}
