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
use Kanvas\Connectors\DealerSocket\Enums\CustomFieldEnum as DealerSocketCustomFieldEnum;
use Kanvas\Connectors\DealerSocket\Services\DealerSocketWorkNoteService;
use Kanvas\Connectors\DriveCentric\Actions\AddCommentToDealAction;
use Kanvas\Connectors\DriveCentric\Enums\ConfigurationEnum as DriveCentricConfigurationEnum;
use Kanvas\Connectors\Elead\Entities\Lead as EntitiesLead;
use Kanvas\Connectors\Elead\Entities\SalesActivities;
use Kanvas\Connectors\Elead\Enums\CustomFieldEnum;
use Kanvas\Connectors\VinSolution\Actions\PushNoteToLeadAction;
use Kanvas\Connectors\VinSolution\Enums\CustomFieldEnum as EnumsCustomFieldEnum;
use Kanvas\Guild\Leads\Actions\SendMessageToLeadAction;
use Kanvas\Guild\Leads\Enums\ConfigurationEnum as LeadsEnumsConfigurationEnum;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Intelligence\Enums\IntelligenceModeEnum;
use Kanvas\Services\DailyReportService;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\Enums\WorkflowEnum;

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
                ->whereHas('messageType', function ($query) {
                    $query->whereIn('verb', ['mailgun-email', 'twilio-sms', 'whatsapp-contact', 'whatsapp', 'whatsapp-text', 'whatsapp-image']);
                })
                ->whereDate('created_at', now()->toDateString())
                ->whereRaw("DATE_ADD(created_at, INTERVAL {$minutedMessages} MINUTE) <= NOW()")
                ->cursor();

            foreach ($messages as $message) {
                if (! $message->entity() || get_class($message->entity()) !== Lead::class) {
                    $this->info('Message ID ' . $message->getId() . ' is not linked to a Lead entity. Skipping.');
                    $message->setUnlock();

                    continue;
                }

                if (! $message->hasTag(['first-message'])) {
                    $this->info('Message ID ' . $message->getId() . ' does not have "first-message" tag. Skipping.');
                    $message->setUnlock();

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
                if ($lead->get('ai_mode') == IntelligenceModeEnum::OFF->value) {
                    $this->error('AI Mode OFF');
                }
                $isElead = $company->get(CustomFieldEnum::COMPANY->value) !== null;
                $isVinSolutions = $company->get(EnumsCustomFieldEnum::COMPANY->value) !== null;
                $hasBeenContacted = $lead->hasBeenContacted();
                if ($isElead) {
                    // for now only work with elead, missing determining if lead was contacted
                    if (empty($lead->get(CustomFieldEnum::OPPORTUNITY_ID->value))) {
                        $this->info('Lead ID ' . $lead->getId() . ' does not have an Opportunity ID. Skipping message ID ' . $message->getId() . '.');
                        $message->setUnlock();
                        //$message->setPublic();

                        continue;
                    }

                    try {
                        $hasBeenContacted = SalesActivities::hasSalesAgentReachedOut(
                            $lead->app,
                            $lead->company,
                            $lead->get(CustomFieldEnum::OPPORTUNITY_ID->value)
                        ) || $lead->hasBeenContacted();
                    } catch (Exception $e) {
                        $this->error('Error checking sales activity for Lead ID ' . $lead->getId() . ': ' . $e->getMessage());
                    }
                }

                try {
                    if (
                        $hasBeenContacted
                    ) {
                        $message->setUnlock();
                        //$message->setPublic();
                        $this->info('Lead ID ' . $lead->getId() . ' has already been contacted by sales agent. Skipping message ID ' . $message->getId() . '.');

                        continue;
                    }
                } catch (Exception $e) {
                    $this->error('Error checking sales activity for Lead ID ' . $lead->getId() . ': ' . $e->getMessage());

                    continue;
                }
                // @todo we need to determine if the lead was contacted for vin solution
                $messageContent = $lead->get(LeadsEnumsConfigurationEnum::FIRST_MESSAGE->value) ?? '';

                if ($messageContent === '' || empty($messageContent)) {
                    $this->info('Lead ID ' . $lead->getId() . ' does not have a first message configured. Skipping message ID ' . $message->getId() . '.');

                    $message->setUnlock();

                    continue;
                }

                if (! $lead->get('delay_message_sent')) {
                    try {
                        $note = 'Sally sent the first message after the lead had been open for ' . $minutedMessages . ' minutes with no contact from a sales agent.';
                        if ($isElead) {
                            $eLeadOpportunity = EntitiesLead::getById(
                                $lead->app,
                                $lead->company,
                                (string) $lead->get(CustomFieldEnum::OPPORTUNITY_ID->value)
                            );
                            $eLeadOpportunity->addComment($note);
                        } elseif ($isVinSolutions) {
                            new PushNoteToLeadAction(
                                lead: $lead,
                                message: $message,
                            )->execute($note);
                        }
                        $lead->set('delay_message_sent', true);
                    } catch (ClientException $e) {
                        if (Str::contains($e->getMessage(), 'not active')
                            || Str::contains($e->getMessage(), 'InactiveOpportunity')) {
                            $lead->close();
                            $this->info('Lead ID ' . $lead->getId() . ' opportunity is inactive. Closing lead.');
                        } else {
                            $this->error('Error adding comment to Lead ID ' . $lead->getId() . ': ' . $e->getMessage());
                        }

                        continue;
                    } catch (Exception $e) {
                        $this->error('Error adding comment to Lead ID ' . $lead->getId() . ': ' . $e->getMessage());

                        continue;
                    }
                }

                try {
                    new SendMessageToLeadAction($lead)->execute(
                        $communicationChannel,
                        $messageContent,
                        $fromNumber,
                        $title,
                    );
                    $message->setUnlock();
                    $message->setPublic();
                    $message->created_at = date('Y-m-d H:i:s');
                    $message->saveOrFail();
                    $lead->set(
                        LeadsEnumsConfigurationEnum::SENT_FIRST_MESSAGE_AT->value,
                        $message->created_at
                    );

                    //dispatch workflow
                    $message->fireWorkflow(
                        WorkflowEnum::CREATED->value,
                        true,
                        [
                           'app' => $message->app,
                        ]
                    );

                    $this->info('Sent delayed message for Lead ID ' . $lead->getId() . ' for message ID ' . $message->getId());

                    DailyReportService::track(
                        $lead->app,
                        $lead->company,
                        'ai_delayed_message_sent'
                    );
                } catch (Exception $e) {
                    $this->error('Error sending message for Lead ID ' . $lead->getId() . ': ' . $e->getMessage());
                }
            }
            $this->info('Processed messages for company: ' . $company->name);
        }
    }

    protected function resolveCrmIntegration(Companies $company): ?IntegrationsEnum
    {
        if ($company->get(CustomFieldEnum::COMPANY->value) !== null) {
            return IntegrationsEnum::ELEAD;
        }

        if ($company->get(EnumsCustomFieldEnum::COMPANY->value) !== null) {
            return IntegrationsEnum::VIN_SOLUTION;
        }

        if ($company->get(DealerSocketCustomFieldEnum::DEALER_SOCKET_CREDENTIAL->value) !== null) {
            return IntegrationsEnum::DEALERSOCKET;
        }

        if ($company->get(DriveCentricConfigurationEnum::STORE_ID->value) !== null) {
            return IntegrationsEnum::DRIVE_CENTRIC;
        }

        return null;
    }

    protected function addDelayNoteToCrm(
        Lead $lead,
        Message $message,
        ?IntegrationsEnum $crmIntegration,
        string $note
    ): mixed {
        return match ($crmIntegration) {
            IntegrationsEnum::ELEAD => tap(
                EntitiesLead::getById(
                    $lead->app,
                    $lead->company,
                    (string) $lead->get(CustomFieldEnum::OPPORTUNITY_ID->value)
                ),
                fn (EntitiesLead $eLeadOpportunity) => $eLeadOpportunity->addComment($note)
            ),
            IntegrationsEnum::VIN_SOLUTION => new PushNoteToLeadAction(
                lead: $lead,
                message: $message,
            )->execute($note),
            IntegrationsEnum::DEALERSOCKET => new DealerSocketWorkNoteService(
                $lead->app,
                $lead->company
            )->addSimpleNote($lead, $note),
            IntegrationsEnum::DRIVE_CENTRIC => new AddCommentToDealAction($lead)->execute($note),
            default => null,
        };
    }
}
