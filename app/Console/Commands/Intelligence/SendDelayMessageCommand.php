<?php

declare(strict_types=1);

namespace App\Console\Commands\Intelligence;

use Baka\Traits\KanvasJobsTrait;
use Exception;
use Illuminate\Console\Command;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Enums\ConfigurationEnum as CompanyConfigurationEnum;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Elead\Entities\Lead as EntitiesLead;
use Kanvas\Connectors\Elead\Entities\SalesActivities;
use Kanvas\Connectors\Elead\Enums\CustomFieldEnum;
use Kanvas\Guild\Leads\Actions\SendMessageToLeadAction;
use Kanvas\Guild\Leads\Enums\ConfigurationEnum as LeadsEnumsConfigurationEnum;
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

        $companies = Companies::getByCustomFieldBuilder(CompanyConfigurationEnum::MESSAGE_MINUTES_INTERVAL->value, null)->get();

        foreach ($companies as $company) {
            $this->newLine();
            $this->info('Processing company: ' . $company->name);
            $minutedMessages = $company->get(CompanyConfigurationEnum::MESSAGE_MINUTES_INTERVAL->value) ?? 60;
            $messages = Message::fromApp($app)
                ->fromCompany($company)
                ->where('is_locked', 1)
                ->whereRaw("DATE_ADD(created_at, INTERVAL {$minutedMessages} MINUTE) <= NOW()")
                ->cursor();

            foreach ($messages as $message) {
                $communicationChannel = $message->get('communicationChannel');
                $fromNumber = $message->get('from_number');
                $title = $message->get('title');
                $lead = $message->entity();

                $this->info('Processing lead name ' . $lead->people->name . ' for message ID ' . $message->getId());
                if (! $lead instanceof Lead) {
                    $this->error('Message ID ' . $message->getId() . ' is not linked to a Lead entity.');

                    continue;
                }

                // for now only work with elead, missing determining if lead was contacted
                if (empty($lead->get(CustomFieldEnum::OPPORTUNITY_ID->value))) {
                    $this->info('Lead ID ' . $lead->getId() . ' does not have an Opportunity ID. Skipping message ID ' . $message->getId() . '.');
                    $message->is_locked = 0;
                    $message->saveOrFail();

                    continue;
                }

                try {
                    if (SalesActivities::hasSalesAgentReachedOut(
                        $lead->app,
                        $lead->company,
                        $lead->get(CustomFieldEnum::OPPORTUNITY_ID->value)
                    )) {
                        $message->is_locked = 0;
                        $message->saveOrFail();
                        $this->info('Lead ID ' . $lead->getId() . ' has already been contacted by sales agent. Skipping message ID ' . $message->getId() . '.');

                        continue;
                    }
                } catch (Exception $e) {
                    $this->error('Error checking sales activity for Lead ID ' . $lead->getId() . ': ' . $e->getMessage());

                    continue;
                }

                $messageContent = $lead->get(LeadsEnumsConfigurationEnum::FIRST_MESSAGE->value) ?? '';

                if ($messageContent === '' || empty($messageContent)) {
                    $this->info('Lead ID ' . $lead->getId() . ' does not have a first message configured. Skipping message ID ' . $message->getId() . '.');
                    $message->is_locked = 0;
                    $message->saveOrFail();

                    continue;
                }

                new SendMessageToLeadAction($lead)->execute(
                    $communicationChannel,
                    $messageContent,
                    $fromNumber,
                    $title,
                );
                $message->is_locked = 0;
                $message->saveOrFail();
                $lead->set(LeadsEnumsConfigurationEnum::SENT_FIRST_MESSAGE_AT->value, date('Y-m-d H:i:s'));

                $eLeadOpportunity = EntitiesLead::getById($lead->app, $lead->company, (string) $lead->get(CustomFieldEnum::OPPORTUNITY_ID->value));
                $eLeadOpportunity->addComment('Sally has already sent the first message to the lead. It’s been an hour since the lead was created, and no sales agent has contacted them yet.');
            }
            $this->info('Processed messages for company: ' . $company->name);
        }
    }
}
