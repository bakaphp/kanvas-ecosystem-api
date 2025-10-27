<?php

declare(strict_types=1);

namespace Kanvas\Connectors\VinSolution\Workflow;

use Baka\Support\Url;
use Gewaer\Domains\Users\Repositories\UsersRepository;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\VinSolution\Actions\PushNoteToLeadAction;
use Kanvas\Connectors\VinSolution\Enums\ConfigurationEnum;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Intelligence\Sessions\Services\SessionChannelService;
use Kanvas\Notifications\Templates\Blank;
use Kanvas\Social\Messages\Models\Message;
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
                if ($aiChatLink !== null && $fromAgent) {
                    $aiChatLink = Url::getShortUrl($aiChatLink, $app) . '?openInSa=true';
                    $note .= " \n\n View Full Conversation here: {$aiChatLink}";
                }
                $note = ($fromAgent ? 'Sally: ' : 'Customer: ') . $note;

                $vinNote = new PushNoteToLeadAction(
                    lead: $lead,
                    message: $message,
                )->execute($note);

                // Notify managers
                $sentManagerNotification = false;
                if (! $fromAgent && $lead->company->get('ai_manager_notifications')) {
                    $this->notifyManagers($message);
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
     * to another action
     */
    protected function notifyManagers(Message $message): void
    {
        $notification = new Blank(
            templateName: 'agent-manager-notification',
            data: [
                'message' => $message,
                'company' => $message->company,
                'app' => $message->app,
                'user' => $message->user,
            ],
            via: ['sms', 'push', 'expo'],
            entity: $message
        );

        $notification->setSubject('New Customer Engaged with Sally');
        $notification->setPushTemplateName('agent_manager_push_notification');
        $notification->setSmsTemplateName('agent_manager_sms_notification');

        //managers
        $managers = UsersRepository::getCompanyAppUserByRole(
            $message->company,
            $message->app,
            'AiManager'
        )->get();

        foreach ($managers as $manager) {
            $manager->notify(
                $notification
            );
        }
    }
}
