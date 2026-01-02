<?php

declare(strict_types=1);

namespace Kanvas\Connectors\DealerSocket\Activities;

use Baka\Support\Url;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\DealerSocket\Services\DealerSocketWorkNoteService;
use Kanvas\Connectors\VinSolution\Workflow\AddLeadCommentFromAgentMessageActivity as WorkflowAddLeadCommentFromAgentMessageActivity;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Intelligence\Sessions\Services\SessionChannelService;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Override;

class AddLeadCommentFromAgentMessageActivity extends WorkflowAddLeadCommentFromAgentMessageActivity
{
    public $tries = 3;

    #[Override]
    public function execute(Message $message, Apps $app, array $params): array
    {
        $this->overwriteAppService($app);
        $company = $message->company;

        return $this->executeIntegration(
            entity: $message,
            app: $app,
            integration: IntegrationsEnum::DEALERSOCKET,
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

                $channel = $this->getLeadChannelName($channelSlug);

                $agentChannel = '(' . ucfirst($channel ?? 'sms') . ') ';

                $note = ($fromAgent ? $agentChannel . 'Sally: ' : 'Customer: ') . $note;

                if ($aiChatLink !== null) {
                    $aiChatLink = '<a href=' . Url::getShortUrl($aiChatLink, $app) . '?openInSa=true" target="_blank">AI Chat Conversation</a>';
                    $linkText = "<br />View Full Conversation here: {$aiChatLink}";

                    if (strlen($note) + strlen($linkText) > 200) {
                        $note = substr($note, 0, 200 - strlen($linkText) - 5) . '...' . $linkText;
                    } else {
                        $note .= $linkText;
                    }
                }

                $leadNote = new DealerSocketWorkNoteService(
                    $lead->app,
                    $lead->company
                );
                $dealerSocketNote = $leadNote->addHtmlNote($lead, $note);

                // Notify managers
                $sentManagerNotification = false;
                if (! $fromAgent && $lead->company->get('ai_manager_notifications')) {
                    $this->notifyManagers($message, $lead);
                    $sentManagerNotification = true;
                }

                return [
                    'note' => $dealerSocketNote,
                    'from_agent' => $fromAgent,
                    'lead' => $lead->getId(),
                    'sent_manager_notification' => $sentManagerNotification,
                ];
            }
        );
    }
}
