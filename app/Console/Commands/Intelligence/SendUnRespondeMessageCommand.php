<?php

declare(strict_types=1);

namespace App\Console\Commands\Intelligence;

use Baka\Support\Str;
use Baka\Traits\KanvasJobsTrait;
use Exception;
use GuzzleHttp\Exception\ClientException;
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
use Kanvas\Intelligence\Triggers\Enums\TriggersEnum;
use Kanvas\Services\DailyReportService;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Workflow\Enums\WorkflowEnum;

class SendUnRespondeMessageCommand extends Command
{
    use KanvasJobsTrait;

    protected $signature = 'kanvas:intelligence:send-unresponde-message {app_id}';

    public function handle(): void
    {
        $app = Apps::getById((int) $this->argument('app_id'));
        $this->overwriteAppService($app);
        $companies = Companies::getByCustomFieldBuilder(CompanyConfigurationEnum::UN_RESPONDED_SALESPERSON_MESSAGES->value, null)->get();

        foreach ($companies as $company) {
            $this->newLine();
            $this->info('Processing company: ' . $company->name);
            $minutedMessages = $company->get(CompanyConfigurationEnum::UN_RESPONDED_SALESPERSON_MESSAGES->value) ?? 60;
            $messages = Message::fromApp($app)
                ->fromCompany($company)
                ->where('is_locked', 1)
                ->whereHas('messageType', function ($query) {
                    $query->whereIn('verb', ['mailgun-email', 'twilio-sms', 'whatsapp-contact', 'whatsapp', 'whatsapp-text', 'whatsapp-image']);
                })
                ->whereDate('created_at', now()->toDateString())
                ->whereRaw("DATE_ADD(created_at, INTERVAL {$minutedMessages} MINUTE) <= NOW()")
                ->cursor();

            $sentCRMInternalNote = [];
            foreach ($messages as $message) {
                if (! $message->entity() || get_class($message->entity()) !== Lead::class) {
                    $this->info('Message ID ' . $message->getId() . ' is not linked to a Lead entity. Skipping.');
                    $message->setUnlock();
                    $message->setPublic();

                    continue;
                }

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
                    $message->setUnlock();
                    $message->setPublic();

                    continue;
                }

                try {
                    if (SalesActivities::hasSalesAgentReachedOut(
                        $lead->app,
                        $lead->company,
                        $lead->get(CustomFieldEnum::OPPORTUNITY_ID->value)
                    )) {
                        $message->setUnlock();
                        $message->setPublic();
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
                    $message->setUnlock();
                    $message->setPublic();

                    continue;
                }

                try {
                    if ($message->hasTag(['first-message'])) {
                        continue;
                    }

                    new SendMessageToLeadAction($lead)->execute(
                        $communicationChannel,
                        $message->message['content'],
                        $fromNumber,
                        $title,
                    );
                    $message->setUnlock();
                    $message->setPublic();
                    $message->created_at = date('Y-m-d H:i:s');
                    $message->saveOrFail();

                    //dispatch workflow
                    $message->fireWorkflow(
                        WorkflowEnum::CREATED->value,
                        true,
                        [
                           'app' => $message->app,
                        ]
                    );

                    // Check if message does NOT have 'first-message' tag to trigger IA takeover
                    if (! $message->hasTag(['first-message'])) {
                        $lead->fireWorkflow(
                            WorkflowEnum::TRIGGER_AI->value,
                            true,
                            [
                                'trigger_type' => TriggersEnum::AI_TAKEOVER->value,
                            ]
                        );
                        $this->info('Triggered IA takeover workflow for Lead ID ' . $lead->getId());
                    }

                    $this->info('Sent unresponde message for Lead ID ' . $lead->getId() . ' for message ID ' . $message->getId());

                    DailyReportService::track(
                        $lead->app,
                        $lead->company,
                        'ai_unresponde_message_sent'
                    );

                    if (! isset($sentCRMInternalNote[$lead->getId()])) {
                        try {
                            if (! $lead->get('sended_note_un_response')) {
                                $eLeadOpportunity = EntitiesLead::getById(
                                    $lead->app,
                                    $lead->company,
                                    (string) $lead->get(CustomFieldEnum::OPPORTUNITY_ID->value)
                                );
                                $eLeadOpportunity->addComment("The sales agent hasn't responded to the customer's message in 15 minutes. Sally is responding to the customer");
                                $sentCRMInternalNote[$lead->getId()] = true;
                                $lead->set('sended_note_un_response', true);
                            }
                        } catch (ClientException $e) {
                            if (Str::contains($e->getMessage(), 'not active')
                                || Str::contains($e->getMessage(), 'InactiveOpportunity')) {
                                $lead->close();
                                $this->info('Lead ID ' . $lead->getId() . ' opportunity is inactive. Closing lead.');
                            } else {
                                $this->error('Error adding comment to Lead ID ' . $lead->getId() . ': ' . $e->getMessage());
                            }

                            continue;
                        }
                    }
                } catch (Exception $e) {
                    $this->error('Error sending message for Lead ID ' . $lead->getId() . ': ' . $e->getMessage());
                }
            }
            $this->info('Processed messages for company: ' . $company->name);
        }
    }
}
