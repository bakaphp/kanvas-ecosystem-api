<?php

declare(strict_types=1);

namespace Kanvas\Connectors\VinSolution\Workflow;

use Baka\Support\Url;
use Illuminate\Support\Facades\Notification;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\VinSolution\Actions\PushNoteToLeadAction;
use Kanvas\Connectors\VinSolution\Enums\ConfigurationEnum;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Intelligence\Enums\ConfigurationEnum as IntelligenceEnumsConfigurationEnum;
use Kanvas\Intelligence\Sessions\Services\SessionChannelService;
use Kanvas\Intelligence\Tools\CompanyWorkHoursTool;
use Kanvas\Notifications\Templates\Blank;
use Kanvas\Social\Enums\ChannelCategoryEnum;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Users\Repositories\UsersRepository;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\KanvasActivity;

class AddLeadCommentFromAgentMessageActivity extends KanvasActivity
{
    public $tries = 3;

    public function execute(Message $message, Apps $app, array $params): array
    {
        $this->overwriteAppService($app);
        $company = $message->company;
        if (! $company->get(ConfigurationEnum::COMPANY->value)) {
            return [
                'error' => 'Company not found in VinSolution',
            ];
        }

        return $this->executeIntegration(
            entity: $message,
            app: $app,
            integration: IntegrationsEnum::VIN_SOLUTION,
            integrationOperation: function (Message $message, Apps $app, mixed $integrationCompany, array $additionalParams) {
                $lead = $message->entity();

                if (! $lead instanceof Lead) {
                    return $this->failWorkflow([
                        'error' => 'Message is not linked to a Lead entity',
                    ]);
                }

                $note = $message->message['content'] ?? '';

                if (empty($note)) {
                    return $this->failWorkflow([
                        'error' => 'Message content is empty',
                    ]);
                }

                $fromAgent = (bool) ($message->message['from_me'] ?? false);
                $aiChatLink = SessionChannelService::generateChannelLink($lead, $app);

                $channelSlug = $message->channels->first()->slug;

                $channel = ChannelCategoryEnum::getLeadChannelName($channelSlug);

                $agentChannel = '(' . ucfirst($channel ?? 'sms') . ') ';

                $note = ($fromAgent ? $agentChannel . 'Sally: ' : 'Customer: ') . $note;

                if ($aiChatLink !== null) {
                    $aiChatLink = Url::getShortUrl($aiChatLink, $app) . '?openInSa=true';
                    $linkText = "\nView Full Conversation here: {$aiChatLink}";

                    if (strlen($note) + strlen($linkText) > 200) {
                        $note = substr($note, 0, 200 - strlen($linkText) - 5) . '...' . $linkText;
                    } else {
                        $note .= $linkText;
                    }
                }

                $vinNote = new PushNoteToLeadAction(
                    lead: $lead,
                    message: $message,
                )->execute($note);

                // Notify managers
                $sentManagerNotification = false;
                if (! $fromAgent && $lead->company->get('ai_manager_notifications')) {
                    $this->notifyManagers($message, $lead);
                    $sentManagerNotification = true;
                }

                return [
                    'note' => $vinNote,
                    'from_agent' => $fromAgent,
                    'lead' => $lead->getId(),
                    'sent_manager_notification' => $sentManagerNotification,
                ];
            }
        );
    }

    /**
     * @todo this is not the best place but , this is just for the client to test and move
     * to another action.
     */
    protected function notifyManagers(Message $message, Lead $lead): void
    {
        $hoursTool = new CompanyWorkHoursTool($message)->execute();
        if ($hoursTool['status'] !== 'work_hours') {
            return;
        }

        //only notify one time
        if ($lead->company->get(IntelligenceEnumsConfigurationEnum::AI_ENGAGEMENT_MESSAGE_ONLY_ONE_NOTIFICATION->value)
            && $lead->get(ConfigurationEnum::MANAGER_NOTIFIED_AT->value)) {
            return;
        }

        $notification = new Blank(
            templateName: 'agent-manager-notification',
            data: [
                'message' => $message,
                'company' => $message->company,
                'app' => $message->app,
                'user' => $message->user,
            ],
            via: ['sms', 'push', 'expo', 'mail'],
            entity: $message
        );

        $notification->setSubject($lead->people->name . ' Engaged with Sally');
        $notification->setPushTemplateName('agent_manager_push_notification');
        $notification->setSmsTemplateName('agent_manager_sms_notification');

        //managers
        $managers = UsersRepository::getCompanyAppUserByRole(
            $message->company,
            $message->app,
            'BDCManager'
        )->get();

        Notification::send($managers, $notification);
        $lead->set(ConfigurationEnum::MANAGER_NOTIFIED_AT->value, date('Y-m-d H:i:s'));
    }
}
